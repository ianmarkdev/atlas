<?php

namespace App\Console\Commands;

use App\Domain\Ai\GenerationRun;
use App\Domain\Applications\AiAnswerService;
use App\Domain\Applications\AnswerDraft;
use App\Domain\Applications\ApplicationResume;
use App\Domain\Applications\ApplicationResumeService;
use App\Domain\Applications\JobAnalysis;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Ends AI work that started and never finished: the machine running the workers was switched
 * off or the worker killed mid-job, so neither the job nor its `failed()` handler recorded an
 * outcome. Only work that is *running* for longer than `ai.run_stale_after_minutes`: queued
 * work may rightly wait for hours while the AI computer is off.
 */
#[Signature('app:fail-stale-ai-work')]
#[Description('Mark AI work still running after the stale limit as failed, so it can be tried again')]
class FailStaleAiWork extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(TenantContext $tenant, AiAnswerService $answers, ApplicationResumeService $resumes): int
    {
        $before = CarbonImmutable::now()->subMinutes((int) config('ai.run_stale_after_minutes'));

        foreach (Workspace::query()->orderBy('id')->get() as $workspace) {
            $ended = $tenant->run($workspace, fn (): int => $this->analyses($before)
                + $this->refreshes($before)
                + $this->drafts($before, $answers)
                + $this->resumes($before, $resumes)
                + $this->runs($before));

            if ($ended > 0) {
                $this->components->warn("{$workspace->name}: {$ended} stale items ended.");
            }
        }

        return self::SUCCESS;
    }

    private function analyses(CarbonImmutable $before): int
    {
        return JobAnalysis::query()->where('status', 'running')->where('updated_at', '<', $before)->update([
            'status' => 'failed',
            'error_message' => __('The analysis stopped without finishing. Try again.'),
            'completed_at' => now(),
        ]);
    }

    private function refreshes(CarbonImmutable $before): int
    {
        return JobAnalysis::query()->where('rematch_requested_at', '<', $before)->update(['rematch_requested_at' => null]);
    }

    private function drafts(CarbonImmutable $before, AiAnswerService $answers): int
    {
        $drafts = AnswerDraft::query()->whereIn('generation_status', ['running', 'checking'])->where('updated_at', '<', $before)->with('currentVersion')->get();

        foreach ($drafts as $draft) {
            if ($draft->generation_status === 'checking' && $draft->currentVersion !== null) {
                $answers->failClaimCheck($draft->currentVersion, __('the check never finished'));
            } else {
                $answers->fail($draft, __('Writing the draft stopped without finishing. Try again.'));
            }
        }

        return $drafts->count();
    }

    private function resumes(CarbonImmutable $before, ApplicationResumeService $resumes): int
    {
        $stale = ApplicationResume::query()->where('generation_status', 'running')->where('updated_at', '<', $before)->get();

        foreach ($stale as $resume) {
            $resumes->failTailoring($resume, __('Tailoring stopped without finishing. Try again.'));
        }

        return $stale->count();
    }

    /**
     * The runs themselves, as the gateway marks them when their key is used again.
     */
    private function runs(CarbonImmutable $before): int
    {
        return GenerationRun::query()->where('status', 'running')->where('created_at', '<', $before)->update([
            'status' => 'failed',
            'error_code' => 'abandoned',
            'error_message' => __('Never finished.'),
        ]);
    }
}
