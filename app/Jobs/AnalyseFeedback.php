<?php

namespace App\Jobs;

use App\Domain\Ai\AiBudgetExceeded;
use App\Domain\Ai\AiGateway;
use App\Domain\Ai\InvalidModelOutput;
use App\Domain\Ai\LlmConfigurationError;
use App\Domain\Ai\LlmUnavailable;
use App\Domain\Feedback\FeedbackAnalyser;
use App\Domain\Feedback\FeedbackEvent;
use App\Jobs\Middleware\WithWorkspaceContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Learn from an edited AI answer (M10, docs/04 §4.7): the feedback_analysis prompt reads the
 * edit, FeedbackAnalyser checks what it found and stores the lessons as pending proposals.
 * Failures are recorded on the event; the answer itself is never touched.
 */
class AnalyseFeedback implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $workspaceId, public int $feedbackEventId)
    {
        $this->afterCommit();
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new WithWorkspaceContext($this->workspaceId)];
    }

    public function handle(AiGateway $gateway, FeedbackAnalyser $analyser): void
    {
        $event = FeedbackEvent::query()
            ->with(['answerDraft.question.application', 'fromVersion', 'toVersion', 'user'])
            ->find($this->feedbackEventId);

        if ($event === null || $event->status !== 'pending_analysis') {
            return;
        }

        try {
            $result = $gateway->run('feedback_analysis', 'feedback_analysis', $analyser->variables($event), $event->user, purpose: 'feedback_analysis', idempotencyKey: "feedback:{$event->id}");
        } catch (InvalidModelOutput) {
            $this->recordFailure($event, __("The AI's reading of the edit was not usable."));

            return;
        } catch (LlmUnavailable|LlmConfigurationError|AiBudgetExceeded $exception) {
            $this->recordFailure($event, $exception->getMessage());

            return;
        }

        $items = $analyser->route($event, $result->data ?? [], $result->run);

        $event->forceFill([
            'status' => 'analysed',
            'analysis' => ['items' => $items],
            'generation_run_id' => $result->run->id,
            'error' => null,
        ])->save();
    }

    private function recordFailure(FeedbackEvent $event, string $message): void
    {
        $event->forceFill(['status' => 'failed', 'error' => mb_strimwidth($message, 0, 1000, '…')])->save();
    }
}
