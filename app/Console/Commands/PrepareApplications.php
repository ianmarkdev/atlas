<?php

namespace App\Console\Commands;

use App\Domain\Applications\ApplicationAutopilot;
use App\Domain\Applications\JobApplication;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\Workspace;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Applications marked "Prepare in background" on the applications list take their next step
 * here, on the schedule (ApplicationAutopilot). Each step only queues AI work, so a run is quick.
 */
#[Signature('app:prepare-applications {--workspace= : Only this workspace (id)}')]
#[Description('Take the next step for each application being prepared in the background')]
class PrepareApplications extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ApplicationAutopilot $autopilot, TenantContext $tenant): int
    {
        $workspaces = Workspace::query()
            ->when($this->option('workspace') !== null, fn ($query) => $query->whereKey((int) $this->option('workspace')))
            ->orderBy('id')
            ->get();

        foreach ($workspaces as $workspace) {
            $advanced = $tenant->run($workspace, function () use ($autopilot): int {
                $applications = JobApplication::query()->where('autopilot_status', ApplicationAutopilot::RUNNING)->orderBy('id')->get();
                $applications->each(fn (JobApplication $application) => $autopilot->advance($application));

                return $applications->count();
            });

            $this->components->info("{$workspace->name}: {$advanced} in progress.");
        }

        return self::SUCCESS;
    }
}
