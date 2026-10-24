<?php

namespace App\Console\Commands;

use App\Domain\Applications\ApplicationStage;
use App\Domain\Applications\JobApplication;
use App\Domain\Applications\SubmittedWorkRetention;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\Workspace;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Keeps the AI payloads of every submitted application for the long retention, including
 * applications moved to a submitted stage outside this app (Career VA changes the stage in the
 * shared database, which never reaches JobApplicationService::changeStage()). Safe to repeat:
 * a retention is only ever lengthened.
 */
#[Signature('app:retain-submitted-work {--workspace= : Only this workspace (id)}')]
#[Description('Extend the payload retention of every application in a submitted stage')]
class RetainSubmittedWork extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(SubmittedWorkRetention $retention, TenantContext $tenant): int
    {
        $submitted = array_values(array_filter(ApplicationStage::cases(), fn (ApplicationStage $stage): bool => $stage->isSubmitted()));
        $workspaces = Workspace::query()
            ->when($this->option('workspace') !== null, fn ($query) => $query->whereKey((int) $this->option('workspace')))
            ->orderBy('id')
            ->get();

        foreach ($workspaces as $workspace) {
            $kept = $tenant->run($workspace, fn (): int => JobApplication::query()
                ->whereIn('stage', $submitted)
                ->get()
                ->sum(fn (JobApplication $application): int => $retention->retain($application)));
            $this->components->info("{$workspace->name}: {$kept} payloads kept longer.");
        }

        return self::SUCCESS;
    }
}
