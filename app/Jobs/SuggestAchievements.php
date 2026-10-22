<?php

namespace App\Jobs;

use App\Domain\Ai\AiBudgetExceeded;
use App\Domain\Ai\AiGateway;
use App\Domain\Ai\InvalidModelOutput;
use App\Domain\Ai\LlmConfigurationError;
use App\Domain\Ai\LlmUnavailable;
use App\Domain\Knowledge\AchievementSuggester;
use App\Domain\Knowledge\ProposalService;
use App\Jobs\Middleware\WithWorkspaceContext;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * The "knowledge health" pass (M9, docs/04 §4.6): ask the AI which plain bullet points would be
 * stronger with a result, and store its checked suggestions as pending proposals for the
 * candidate. Runs on request and weekly (`app:suggest-achievements`).
 */
class SuggestAchievements implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $workspaceId, public ?int $requestedBy = null)
    {
        $this->afterCommit();
    }

    /**
     * Cache key marking a run in progress (or its last failure) for the suggestions page.
     */
    public static function statusKey(int $workspaceId): string
    {
        return "achievement-suggestions:{$workspaceId}";
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new WithWorkspaceContext($this->workspaceId)];
    }

    public function handle(AiGateway $gateway, AchievementSuggester $suggester, ProposalService $proposals): void
    {
        $candidates = $suggester->candidates();

        if ($candidates === []) {
            Cache::forget(self::statusKey($this->workspaceId));

            return;
        }

        $presented = $suggester->present($candidates);
        $requester = $this->requestedBy === null ? null : User::query()->find($this->requestedBy);

        try {
            $result = $gateway->run('achievement_suggestions', 'achievement_suggestions', ['bullets' => $presented['text']], $requester, purpose: 'achievement_suggestions');
        } catch (InvalidModelOutput) {
            Cache::put(self::statusKey($this->workspaceId), ['status' => 'failed', 'error' => __('The AI answer was not usable. Try again later.')], now()->addDay());

            return;
        } catch (LlmUnavailable|LlmConfigurationError|AiBudgetExceeded $exception) {
            Cache::put(self::statusKey($this->workspaceId), ['status' => 'failed', 'error' => $exception->getMessage()], now()->addDay());

            return;
        }

        foreach ($suggester->accepted($result->data ?? [], $presented['map']) as $suggestion) {
            $proposals->suggestAchievement($suggestion['claim'], $suggestion['question'], $suggestion['draft']);
        }

        Cache::forget(self::statusKey($this->workspaceId));
    }

    public function failed(): void
    {
        Cache::put(self::statusKey($this->workspaceId), ['status' => 'failed', 'error' => __('Looking for achievements failed. Try again later.')], now()->addDay());
    }
}
