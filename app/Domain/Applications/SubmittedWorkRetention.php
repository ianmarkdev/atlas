<?php

namespace App\Domain\Applications;

use App\Domain\Ai\GenerationPayload;
use Carbon\CarbonImmutable;

/**
 * Keeps the prompts and responses behind a sent application (its job analyses, tailored resume
 * versions and AI answer versions) for `ai.submitted_payload_retention_days`, instead of the
 * short debugging period every payload starts with. A retention is only ever lengthened, so
 * moving between submitted stages again is harmless.
 */
class SubmittedWorkRetention
{
    /**
     * @return int how many payloads were kept longer
     */
    public function retain(JobApplication $application): int
    {
        $until = CarbonImmutable::now()->addDays((int) config('ai.submitted_payload_retention_days'));

        $analysisRuns = JobAnalysis::query()
            ->where('job_application_id', $application->id)
            ->whereNotNull('generation_run_id')
            ->select('generation_run_id');
        $resumeRuns = ApplicationResumeVersion::query()
            ->whereIn('application_resume_id', ApplicationResume::query()->where('job_application_id', $application->id)->select('id'))
            ->whereNotNull('generation_run_id')
            ->select('generation_run_id');
        $answerRuns = AnswerDraftVersion::query()
            ->whereIn('answer_draft_id', AnswerDraft::query()
                ->whereIn('application_question_id', ApplicationQuestion::query()->where('job_application_id', $application->id)->select('id'))
                ->select('id'))
            ->whereNotNull('generation_run_id')
            ->select('generation_run_id');

        return GenerationPayload::query()
            ->whereIn('generation_run_id', $analysisRuns->union($resumeRuns)->union($answerRuns))
            ->where('expires_at', '<', $until)
            ->update(['expires_at' => $until]);
    }
}
