<?php

namespace App\Jobs;

use App\Domain\Ai\AiBudgetExceeded;
use App\Domain\Ai\AiGateway;
use App\Domain\Ai\AiQueue;
use App\Domain\Ai\InvalidModelOutput;
use App\Domain\Ai\LlmConfigurationError;
use App\Domain\Ai\LlmRouter;
use App\Domain\Ai\LlmUnavailable;
use App\Domain\Ai\PromptBudget;
use App\Domain\Ai\PromptRegistry;
use App\Domain\Applications\BriefChecker;
use App\Domain\Applications\ClarificationService;
use App\Domain\Applications\EvidenceMatcher;
use App\Domain\Applications\JobAnalysis;
use App\Domain\Applications\ResumeRanker;
use App\Jobs\Middleware\WithWorkspaceContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Analyse one application's job description (M7): the jd_analysis prompt through AiGateway, the
 * server's own checks on the brief, then evidence matching. A job description longer than the
 * model's window is read from its start (PromptBudget; `brief.shortened_input` records how
 * much). The outcome — including failures — is stored on the JobAnalysis row, which the page polls. Not retried automatically: the gateway
 * already retries the provider, and the user can re-analyse.
 */
class AnalyseJobApplication implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Local models can take minutes on a laptop. */
    public int $timeout = 900;

    public function __construct(public int $workspaceId, public int $analysisId)
    {
        $this->afterCommit();
        $this->onQueue(AiQueue::for('jd_analysis'));
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new WithWorkspaceContext($this->workspaceId)];
    }

    public function handle(AiGateway $gateway, BriefChecker $checker, EvidenceMatcher $matcher, ResumeRanker $ranker, ClarificationService $clarifications, LlmRouter $router, PromptRegistry $prompts, PromptBudget $budget): void
    {
        $analysis = JobAnalysis::query()->with(['application', 'posting', 'requester'])->find($this->analysisId);

        if ($analysis === null || ! $analysis->isPending()) {
            return;
        }

        $analysis->forceFill(['status' => 'running'])->save();
        $application = $analysis->application;
        $jobDescription = $analysis->posting->raw_text;

        $variables = [
            'company_name' => $application->companyLabel(),
            'role_title' => $application->role_title,
            'job_description' => '',
        ];

        try {
            // A job description longer than the model's window is analysed from its start; the
            // brief says how much was read, so the page can warn that later requirements may be missing.
            $room = $budget->remaining($router->route('jd_analysis'), $prompts->get('jd_analysis'), $variables);
            $variables['job_description'] = PromptBudget::shorten($jobDescription, $room, __('[… the rest of the job description was left out to fit the model\'s window]'));

            $result = $gateway->run(
                'jd_analysis',
                'jd_analysis',
                $variables,
                $analysis->requester,
                purpose: 'jd_analysis',
                idempotencyKey: "jd_analysis:{$analysis->ulid}",
            );
        } catch (InvalidModelOutput $exception) {
            $this->fail($analysis, 'invalid_output', $exception->getMessage(), $exception->generationRunId);

            return;
        } catch (LlmUnavailable|LlmConfigurationError|AiBudgetExceeded $exception) {
            $this->fail($analysis, 'failed', $exception->getMessage());

            return;
        }

        $brief = $checker->check($result->data ?? [], $jobDescription);
        $brief['shortened_input'] = $variables['job_description'] === $jobDescription ? null : [
            'read_characters' => mb_strlen($variables['job_description']),
            'total_characters' => mb_strlen($jobDescription),
        ];
        $matches = $matcher->match($brief, $application->candidate_profile_id);

        $analysis->forceFill([
            'status' => 'succeeded',
            'generation_run_id' => $result->run->id,
            'prompt_version' => $result->run->prompt_version,
            'brief' => $brief,
            'matches' => $matches,
            'resume_ranking' => $ranker->rank($matches, $application->candidate_profile_id),
            'matched_at' => now(),
            'completed_at' => now(),
            'error_message' => null,
        ])->save();

        $clarifications->syncFromAnalysis($analysis);
    }

    private function fail(JobAnalysis $analysis, string $status, string $message, ?int $runId = null): void
    {
        $analysis->forceFill([
            'status' => $status,
            'generation_run_id' => $runId,
            'error_message' => mb_strimwidth($message, 0, 1000, '…'),
            'completed_at' => now(),
        ])->save();
    }
}
