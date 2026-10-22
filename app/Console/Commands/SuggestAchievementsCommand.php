<?php

namespace App\Console\Commands;

use App\Domain\Tenancy\Workspace;
use App\Jobs\SuggestAchievements;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

#[Signature('app:suggest-achievements {--workspace= : Only this workspace (id)}')]
#[Description('Queue the AI pass that suggests turning plain bullet points into achievements (M9)')]
class SuggestAchievementsCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $workspaces = Workspace::query()
            ->when($this->option('workspace') !== null, fn ($query) => $query->whereKey((int) $this->option('workspace')))
            ->orderBy('id')
            ->get();

        foreach ($workspaces as $workspace) {
            Cache::put(SuggestAchievements::statusKey($workspace->id), ['status' => 'queued', 'error' => null], now()->addHour());
            SuggestAchievements::dispatch($workspace->id);
            $this->components->info("{$workspace->name}: queued.");
        }

        return self::SUCCESS;
    }
}
