<?php

namespace App\Domain\Applications;

use App\Domain\Audit\AuditLogger;
use App\Jobs\AnalyseJobApplication;
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
     * Re-run evidence matching (after the library changed) without calling the model again.
     */
    public function refreshMatches(JobAnalysis $analysis): void
    {
        if ($analysis->status !== 'succeeded' || $analysis->brief === null) {
            return;
        }

        $analysis->forceFill([
            'matches' => $this->matcher->match($analysis->brief, $analysis->application->candidate_profile_id),
            'matched_at' => now(),
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
        $found = array_values((array) ($analysis->brief['questions'] ?? []));
        $normalise = fn (string $text): string => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
        $existing = array_map($normalise, $analysis->application->questions()->pluck('prompt_text')->all());
        $new = [];

        foreach (array_unique($indexes) as $index) {
            $prompt = trim((string) ($found[$index] ?? ''));

            if ($prompt !== '' && ! in_array($normalise($prompt), $existing, true)) {
                $new[] = ['prompt' => mb_substr($prompt, 0, 2000), 'word_limit' => null, 'char_limit' => null];
                $existing[] = $normalise($prompt);
            }
        }

        $this->questions->addMany($analysis->application, $new, $actor);

        return count($new);
    }
}
