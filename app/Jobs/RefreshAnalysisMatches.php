<?php

namespace App\Jobs;

use App\Domain\Ai\AiQueue;
use App\Domain\Applications\JobAnalysis;
use App\Domain\Applications\JobAnalysisService;
use App\Jobs\Middleware\WithWorkspaceContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * "Refresh evidence": match an analysis again against the current library and resumes, without
 * calling the model. Queued because the searches embed each requirement with the local model,
 * which a web request (possibly on another machine) cannot rely on reaching.
 */
class RefreshAnalysisMatches implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public int $workspaceId, public int $analysisId)
    {
        $this->afterCommit();
        $this->onQueue(AiQueue::embeddings());
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new WithWorkspaceContext($this->workspaceId)];
    }

    public function handle(JobAnalysisService $analyses): void
    {
        $analysis = JobAnalysis::query()->with('application')->find($this->analysisId);

        if ($analysis === null || $analysis->rematch_requested_at === null) {
            return;
        }

        $analyses->refreshMatches($analysis);
    }

    public function failed(): void
    {
        WithWorkspaceContext::run($this->workspaceId, fn () => JobAnalysis::query()
            ->whereKey($this->analysisId)
            ->update(['rematch_requested_at' => null]));
    }
}
