<?php

namespace App\Console\Commands;

use App\Domain\Applications\ApplicationStage;
use App\Domain\Applications\JobAnalysisService;
use App\Domain\Applications\JobApplication;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\Workspace;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Applications saved without this app (Career VA writes captures straight to the shared
 * database and cannot queue jobs) get their first analysis here, on the schedule. Only recent,
 * open applications with a job description and no analysis at all: one that failed is not
 * retried by itself, so a broken model cannot loop.
 */
#[Signature('app:analyse-new-applications {--workspace= : Only this workspace (id)} {--limit=20 : At most this many per workspace and run}')]
#[Description('Queue an analysis for each recent application that has a job description and was never analysed')]
class AnalyseNewApplications extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(JobAnalysisService $analyses, TenantContext $tenant): int
    {
        $workspaces = Workspace::query()
            ->when($this->option('workspace') !== null, fn ($query) => $query->whereKey((int) $this->option('workspace')))
            ->orderBy('id')
            ->get();

        foreach ($workspaces as $workspace) {
            $queued = $tenant->run($workspace, fn (): int => $this->queueFor($analyses));
            $this->components->info("{$workspace->name}: {$queued} queued.");
        }

        return self::SUCCESS;
    }

    private function queueFor(JobAnalysisService $analyses): int
    {
        $applications = JobApplication::query()
            ->where('created_at', '>=', now()->subDays((int) config('ai.auto_analysis_days')))
            ->whereNotIn('stage', [ApplicationStage::Rejected, ApplicationStage::Withdrawn])
            ->whereHas('posting')
            ->whereDoesntHave('analyses')
            ->with('owner')
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();
        $queued = 0;

        foreach ($applications as $application) {
            if ($application->owner === null) {
                continue;
            }

            try {
                // Requested in the name of whoever saved it, as if they had clicked "Analyse".
                $analyses->request($application, $application->owner);
                $queued++;
            } catch (ValidationException) {
                // Started meanwhile, or its job description went: nothing to do.
            }
        }

        return $queued;
    }
}
