<?php

namespace App\Domain\Applications;

use App\Domain\Audit\AuditLogger;
use App\Jobs\AnalyseJobApplication;
use App\Jobs\RefreshAnalysisMatches;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Starts analyses, refreshes their evidence and turns the questions they found into the
 * application's questions. The analysis itself runs in AnalyseJobApplication.
 */
class JobAnalysisService
{
    public function __construct(
        private AuditLogger $audit,
        private EvidenceMatcher $matcher,
        private ResumeRanker $ranker,
        private ApplicationQuestionService $questions,
        private ClarificationService $clarifications,
    ) {}

    /**
     * Queue an analysis of the application's current job description.
     *
     * @throws ValidationException without a job description, or while one is already running
     */
    public function request(JobApplication $application, User $actor): JobAnalysis
    {
        return DB::transaction(function () use ($application, $actor): JobAnalysis {
            JobApplication::query()->whereKey($application->id)->lockForUpdate()->first();
            $posting = $application->posting()->first();

            if ($posting === null) {
                throw ValidationException::withMessages(['analysis' => __('Paste the job description first: there is nothing to analyse.')]);
            }

            if (JobAnalysis::query()->where('job_application_id', $application->id)->whereIn('status', JobAnalysis::PENDING)->exists()) {
                throw ValidationException::withMessages(['analysis' => __('An analysis is already running for this application.')]);
            }

            $analysis = new JobAnalysis;
            $analysis->forceFill([
                'job_application_id' => $application->id,
                'job_posting_id' => $posting->id,
                'status' => 'queued',
                'requested_by' => $actor->id,
            ])->save();

            $this->audit->record('application.analysis_requested', $application, ['analysis' => $analysis->ulid], $actor);

            AnalyseJobApplication::dispatch($application->workspace_id, $analysis->id);

            return $analysis;
        });
    }

    /**
     * Queue "Refresh evidence" (RefreshAnalysisMatches). A refresh already waiting is not queued
     * twice.
     *
     * @return bool whether the analysis can be matched again
     */
    public function requestRefresh(JobAnalysis $analysis): bool
    {
        return DB::transaction(function () use ($analysis): bool {
            $analysis = JobAnalysis::query()->whereKey($analysis->id)->lockForUpdate()->firstOrFail();

            if (! $analysis->isRefreshable()) {
                return false;
            }

            if ($analysis->rematch_requested_at === null) {
                $analysis->forceFill(['rematch_requested_at' => now()])->save();
                RefreshAnalysisMatches::dispatch($analysis->workspace_id, $analysis->id);
            }

            return true;
        });
    }

    /**
     * Re-run evidence matching and the resume ranking (after the library or a resume changed)
     * without calling the model again. Ends a requested refresh.
     */
    public function refreshMatches(JobAnalysis $analysis): void
    {
        if ($analysis->status !== 'succeeded' || $analysis->brief === null) {
            $analysis->forceFill(['rematch_requested_at' => null])->save();

            return;
        }

        $profileId = $analysis->application->candidate_profile_id;
        $matches = $this->matcher->match($analysis->brief, $profileId);

        $analysis->forceFill([
            'matches' => $matches,
            'resume_ranking' => $this->ranker->rank($matches, $profileId),
            'matched_at' => now(),
            'rematch_requested_at' => null,
        ])->save();

        $this->clarifications->syncFromAnalysis($analysis);
    }

    /**
     * Add the questions the analysis found (by index) to the application, skipping ones that are
     * already there (same text, ignoring case and spacing).
     *
     * @param  list<int>  $indexes
     * @return int how many were added
     */
    public function addQuestions(JobAnalysis $analysis, array $indexes, User $actor): int
    {
        $notAdded = $this->questionsNotAdded($analysis);
        $new = [];
        $seen = [];

        foreach (array_unique($indexes) as $index) {
            $prompt = $notAdded[$index] ?? null;

            if ($prompt !== null && ! isset($seen[self::normaliseQuestion($prompt)])) {
                $new[] = ['prompt' => mb_substr($prompt, 0, 2000), 'word_limit' => null, 'char_limit' => null];
                $seen[self::normaliseQuestion($prompt)] = true;
            }
        }

        $this->questions->addMany($analysis->application, $new, $actor, origin: 'extracted');

        return count($new);
    }

    /**
     * The questions the analysis found that are not on the application yet, keyed by their index
     * in the brief. They are added only when someone confirms them: a job description may hold
     * text aimed at the AI (docs/04 §4.8).
     *
     * @return array<int, string>
     */
    public function questionsNotAdded(JobAnalysis $analysis): array
    {
        $existing = array_flip(array_map(self::normaliseQuestion(...), $analysis->application->questions()->pluck('prompt_text')->all()));
        $notAdded = [];

        foreach (array_values((array) ($analysis->brief['questions'] ?? [])) as $index => $question) {
            $prompt = trim((string) $question);

            if ($prompt !== '' && ! isset($existing[self::normaliseQuestion($prompt)])) {
                $notAdded[$index] = $prompt;
            }
        }

        return $notAdded;
    }

    /**
     * What the cover letter should cover: the questions the latest analysis found that are not on
     * the application as questions of their own. None while the analysis warns of text aimed at
     * the AI, since nobody has confirmed these points.
     *
     * @return list<string>
     */
    public function coverLetterPoints(JobApplication $application): array
    {
        $analysis = $application->analyses()->where('status', 'succeeded')->first();

        if ($analysis === null || ($analysis->brief['injection_warnings'] ?? []) !== [] || ($analysis->brief['server_injection_warnings'] ?? []) !== []) {
            return [];
        }

        return array_values($this->questionsNotAdded($analysis));
    }

    /**
     * A question's text for spotting the same question twice (case and spacing ignored).
     */
    public static function normaliseQuestion(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }
}
