<?php

namespace App\Domain\Ai;

use App\Domain\Tenancy\Workspace;
use Carbon\CarbonImmutable;

/**
 * Monthly spending ceiling for paid providers (`workspaces.monthly_ai_budget_micros`, default
 * US$30). Local models cost nothing and are never limited. Costs come from the price table in
 * config/ai.php; a paid model without a price is refused rather than guessed.
 */
class BudgetGuard
{
    /**
     * Estimated cost of a request before sending it: ~4 characters per input token, and the
     * full output limit.
     *
     * @throws LlmConfigurationError for a paid model without a price
     */
    public function estimate(TaskRoute $route, LlmRequest $request): int
    {
        if (! $route->external) {
            return 0;
        }

        $characters = mb_strlen($request->system) + array_sum(array_map(fn (array $message): int => mb_strlen($message['content']), $request->messages));

        return $this->cost($route, intdiv($characters, 4) + 1, $request->maxTokens);
    }

    /**
     * @throws LlmConfigurationError for a paid model without a price
     */
    public function cost(TaskRoute $route, int $inputTokens, int $outputTokens): int
    {
        if (! $route->external) {
            return 0;
        }

        $prices = config("ai.prices.{$route->model}");

        if (! is_array($prices)) {
            throw new LlmConfigurationError("No price is configured for \"{$route->model}\" in config/ai.php (prices), so the budget cannot be enforced.");
        }

        return (int) ceil(($inputTokens * (int) $prices['input'] + $outputTokens * (int) $prices['output']) / 1_000_000);
    }

    /**
     * Spending this calendar month (UTC), including runs still in flight (they reserve their estimate).
     */
    public function spentThisMonth(Workspace $workspace): int
    {
        return (int) GenerationRun::query()
            ->where('workspace_id', $workspace->id)
            ->where('created_at', '>=', CarbonImmutable::now('UTC')->startOfMonth())
            ->sum('cost_micros');
    }

    /**
     * @throws AiBudgetExceeded
     */
    public function ensureAffordable(Workspace $workspace, int $estimateMicros): void
    {
        if ($estimateMicros === 0) {
            return;
        }

        $spent = $this->spentThisMonth($workspace);

        if ($spent + $estimateMicros > $workspace->monthly_ai_budget_micros) {
            throw new AiBudgetExceeded(sprintf(
                'This would exceed the monthly AI budget (US$%.2f of US$%.2f used). Raise the budget, wait for next month, or use Ollama.',
                $spent / 1e6,
                $workspace->monthly_ai_budget_micros / 1e6,
            ));
        }
    }
}
