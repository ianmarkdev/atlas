<?php

namespace App\Domain\Applications;

use App\Domain\Audit\AuditLogger;
use App\Domain\Resumes\ResumeVariant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Prepares marked applications in the background, as if someone clicked through them: analyse
 * the job description, add the questions it found and a cover letter, use the best-ranked resume
 * from the library as it is (not tailored), answer each question from the answer library or else
 * with an AI draft, then draft the cover letter once the other answers are written. advance() takes the
 * next step each scheduler run (app:prepare-applications) and never waits for the AI itself.
 *
 * Nothing is approved: it ends `ready` for review, or `stopped` with a note when something failed
 * or needs a person. Work that failed before this run is tried again; work that fails during it
 * stops the run, so a broken model cannot loop. Questions found in a job description that may
 * hold text aimed at the AI are not added (docs/04 §4.8): the note says to check them.
 */
class ApplicationAutopilot
{
    public const RUNNING = 'running';

    public const READY = 'ready';

    public const STOPPED = 'stopped';

    public function __construct(
        private AuditLogger $audit,
        private JobAnalysisService $analyses,
        private ApplicationQuestionService $questions,
        private AiAnswerService $answers,
        private ApplicationResumeService $resumes,
        private LibraryAnswerFinder $library,
    ) {}

    /**
     * Start (or start again) preparing the application in the background.
     */
    public function start(JobApplication $application, User $actor): void
    {
        $application->forceFill([
            'autopilot_status' => self::RUNNING,
            'autopilot_note' => null,
            'autopilot_requested_by' => $actor->id,
            'autopilot_requested_at' => now(),
            'autopilot_questions_added_at' => null,
            'autopilot_finished_at' => null,
        ])->save();

        $this->audit->record('application.autopilot_started', $application, [], $actor);
    }

    /**
     * Stop taking steps. AI work already queued still finishes and is kept as a draft.
     */
    public function stop(JobApplication $application, User $actor): void
    {
        if ($application->autopilot_status === null) {
            return;
        }

        $application->forceFill(['autopilot_status' => null, 'autopilot_note' => null, 'autopilot_finished_at' => now()])->save();

        $this->audit->record('application.autopilot_stopped', $application, [], $actor);
    }

    /**
     * Take the next step(s) for a running application.
     */
    public function advance(JobApplication $application): void
    {
        if ($application->autopilot_status !== self::RUNNING) {
            return;
        }

        $actor = $application->autopilot_requested_by === null ? null : User::query()->find($application->autopilot_requested_by);

        if ($actor === null) {
            $this->finish($application, self::STOPPED, __('Whoever started it is no longer in the workspace. Start it again.'));

            return;
        }

        if ($application->stage->isSubmitted() || $application->stage->isClosed()) {
            $this->finish($application, self::STOPPED, __('The application was sent or closed.'));

            return;
        }

        try {
            $this->step($application, $actor);
        } catch (ValidationException $exception) {
            $this->finish($application, self::STOPPED, (string) Arr::first(Arr::flatten($exception->errors())));
        }
    }

    /**
     * @throws ValidationException
     */
    private function step(JobApplication $application, User $actor): void
    {
        $since = $application->autopilot_requested_at ?? CarbonImmutable::now();
        $posting = $application->posting()->first();

        if ($posting === null) {
            $this->finish($application, self::STOPPED, __('Paste the job description first: there is nothing to analyse.'));

            return;
        }

        $analysis = $application->analyses()->first();

        if ($analysis?->isPending()) {
            return;
        }

        if ($analysis !== null && $analysis->status !== 'succeeded' && $analysis->created_at >= $since) {
            $this->finish($application, self::STOPPED, __('The job analysis failed: :reason', ['reason' => $analysis->error_message ?? __('no reason given')]));

            return;
        }

        if ($analysis === null || $analysis->status !== 'succeeded' || $analysis->job_posting_id !== $posting->id) {
            $this->analyses->request($application, $actor);

            return;
        }

        if ($application->autopilot_questions_added_at === null) {
            $this->addQuestions($application, $analysis, $actor);
        }

        $this->pickResume($application, $analysis, $actor);

        $questions = ApplicationQuestion::query()
            ->where('job_application_id', $application->id)
            ->with('answer.currentVersion')
            ->orderBy('position')
            ->get();
        [$coverLetters, $others] = $questions->partition(fn (ApplicationQuestion $question): bool => $question->isCoverLetter());

        $answersBusy = $this->answer($application, $others, $actor, $since, fromLibrary: true);

        if ($application->autopilot_status !== self::RUNNING) {
            return;
        }

        // The cover letter is written from the resume and the other answers' evidence.
        $coverBusy = $answersBusy || $this->answer($application, $coverLetters, $actor, $since, fromLibrary: false);

        if ($application->autopilot_status === self::RUNNING && ! $coverBusy) {
            $this->finish($application, self::READY, $application->autopilot_note);
        }
    }

    /**
     * Add the questions the analysis found and the cover letter, once per run.
     */
    private function addQuestions(JobApplication $application, JobAnalysis $analysis, User $actor): void
    {
        $found = $this->analyses->questionsNotAdded($analysis);

        if ($found !== [] && JobAnalysisService::warnsOfInjection($analysis)) {
            $this->note($application, __('The job description may hold text aimed at the AI, so the questions found in it were not added. Check them on the Questions page.'));
        } elseif ($found !== []) {
            $this->analyses->addQuestions($analysis, array_keys($found), $actor);
        }

        $this->questions->coverLetter($application, $actor);
        $application->forceFill(['autopilot_questions_added_at' => now()])->save();
    }

    /**
     * Copy the best-ranked resume from the library into the application when it has none. It is
     * used as it is: not tailored.
     *
     * @throws ValidationException
     */
    private function pickResume(JobApplication $application, JobAnalysis $analysis, User $actor): void
    {
        if (ApplicationResume::query()->where('job_application_id', $application->id)->exists()) {
            return;
        }

        $variant = self::bestVariant($analysis, $application->candidate_profile_id);

        if ($variant === null) {
            $this->note($application, __('There is no resume to use: add one on your Profile.'));

            return;
        }

        [$document, $snapshot] = $this->resumes->copyOf($variant);
        $this->resumes->start($application, $variant, $document, $snapshot, $actor);
    }

    /**
     * Answer each question without text: from the library when allowed and it has the answer,
     * else with an AI draft (once per run).
     *
     * @param  Collection<int, ApplicationQuestion>  $questions
     * @return bool whether an answer is still being written or checked
     *
     * @throws ValidationException
     */
    private function answer(JobApplication $application, Collection $questions, User $actor, CarbonImmutable $since, bool $fromLibrary): bool
    {
        $busy = false;

        foreach ($questions as $question) {
            $draft = $question->answer;

            if ($draft?->isApproved()) {
                continue;
            }

            if ($draft !== null && ($draft->isGenerating() || $draft->generation_status === 'checking')) {
                $busy = true;

                continue;
            }

            if ($draft !== null && trim($draft->body()) !== '') {
                continue;
            }

            if ($draft?->generation_status === 'failed' && $draft->generation_requested_at >= $since) {
                $this->finish($application, self::STOPPED, __('The AI could not draft “:question”: :reason', [
                    'question' => mb_strimwidth($question->prompt_text, 0, 80, '…'),
                    'reason' => $draft->generation_error ?? __('no reason given'),
                ]));

                return false;
            }

            $entry = $fromLibrary ? $this->library->find($question->prompt_text, $application->candidate_profile_id) : null;

            if ($entry !== null) {
                $this->questions->saveAnswer($question, $entry->body(), $entry, $draft?->lock_version ?? 0, $actor);

                continue;
            }

            $this->answers->request($question, $actor, bulk: true);
            $busy = true;
        }

        return $busy;
    }

    /**
     * The resume that covers the job best (ResumeRanker), else the default one.
     */
    private static function bestVariant(JobAnalysis $analysis, int $candidateProfileId): ?ResumeVariant
    {
        $variants = ResumeVariant::query()->active()->where('candidate_profile_id', $candidateProfileId)->with('currentRevision')->get();
        $best = $analysis->resume_ranking['resumes'][0]['ulid'] ?? null;

        return $variants->firstWhere('ulid', $best)
            ?? $variants->sortByDesc(fn (ResumeVariant $variant): int => $variant->is_default ? 1 : 0)->first();
    }

    private function note(JobApplication $application, string $line): void
    {
        $lines = array_filter(explode("\n", (string) $application->autopilot_note));

        if (! in_array($line, $lines, true)) {
            $application->forceFill(['autopilot_note' => implode("\n", [...$lines, $line])])->save();
        }
    }

    private function finish(JobApplication $application, string $status, ?string $note): void
    {
        if ($status === self::STOPPED && $note !== null) {
            $this->note($application, $note);
            $note = $application->autopilot_note;
        }

        $application->forceFill(['autopilot_status' => $status, 'autopilot_note' => $note, 'autopilot_finished_at' => now()])->save();
    }
}
