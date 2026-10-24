<?php

namespace App\Domain\Ai;

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\Workspace;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The one path to a text-generation model (M4). For a task and a prompt it: routes to the
 * configured provider (local unless external use is allowed), reserves budget for paid calls,
 * calls the model, and validates the output against the prompt's schema on the server (one
 * repair retry, then `invalid_output`). Every call is recorded in `generation_runs`; prompt and
 * response are kept encrypted for a short time in `generation_payloads`.
 *
 * Validating the shape is not grounding: callers run ClaimGroundingValidator on claim labels.
 */
class AiGateway
{
    public function __construct(
        private LlmRouter $router,
        private PromptRegistry $prompts,
        private JsonSchemaValidator $validator,
        private BudgetGuard $budget,
        private TenantContext $tenant,
    ) {}

    /**
     * @param  array<string, string>  $variables  prompt placeholders
     * @param  string|null  $provider  force a provider (comparisons); null = the task's configured one
     *
     * @throws LlmConfigurationError before anything is sent (unknown task/prompt, external not allowed, no API key, no price)
     * @throws AiBudgetExceeded
     * @throws LlmUnavailable
     * @throws GenerationInProgress when another call with the same idempotency key is still running
     * @throws InvalidModelOutput
     */
    public function run(
        string $task,
        string $promptKey,
        array $variables,
        ?User $actor = null,
        ?string $purpose = null,
        ?string $provider = null,
        ?string $idempotencyKey = null,
        ?int $promptVersion = null,
    ): GenerationResult {
        $route = $this->router->route($task, $provider);
        $prompt = $this->prompts->get($promptKey, $promptVersion);
        $request = new LlmRequest(
            model: $route->model,
            system: $prompt->system,
            messages: [['role' => 'user', 'content' => $prompt->render($variables)]],
            maxTokens: $route->maxTokens,
            temperature: $route->temperature,
            timeoutSeconds: $route->timeoutSeconds,
            schema: $prompt->schema,
            contextTokens: $route->contextTokens,
        );

        $run = $this->reserve($route, $prompt, $request, $actor, $purpose, $idempotencyKey);

        if ($run instanceof GenerationResult) {
            return $run;
        }

        $inputTokens = 0;
        $outputTokens = 0;
        $latency = 0;
        $responses = [];

        try {
            for ($attempt = 1; $attempt <= 2; $attempt++) {
                $response = $route->client->complete($request);
                $responses[] = $response->text;
                $inputTokens += $response->inputTokens;
                $outputTokens += $response->outputTokens;
                $latency += $response->latencyMs;

                if ($prompt->schema === null) {
                    return $this->succeed($run, $route, $request, $responses, null, $response->text, $inputTokens, $outputTokens, $latency, $attempt);
                }

                [$data, $errors] = $this->parse($response->text, $prompt->schema);

                if ($errors === []) {
                    return $this->succeed($run, $route, $request, $responses, $data, $response->text, $inputTokens, $outputTokens, $latency, $attempt);
                }

                // One repair attempt: show the model its output and what is wrong with it.
                $request = $request->withTurns(
                    ['role' => 'assistant', 'content' => $response->text],
                    ['role' => 'user', 'content' => "That output is not valid. Fix these problems and reply with only the corrected JSON:\n- ".implode("\n- ", array_slice($errors, 0, 20))],
                );
            }

            $this->finish($run, $route, $request, $responses, null, 'invalid_output', $inputTokens, $outputTokens, $latency, 2, 'invalid_output', implode('; ', array_slice($errors, 0, 5)));

            throw new InvalidModelOutput("The model's output did not match the expected structure, even after a repair attempt.", $errors, $run->id);
        } catch (LlmUnavailable|LlmConfigurationError $exception) {
            $this->finish($run, $route, $request, $responses, null, 'failed', $inputTokens, $outputTokens, $latency, count($responses), $exception instanceof LlmUnavailable ? 'unavailable' : 'configuration', $exception->getMessage());

            throw $exception;
        }
    }

    /**
     * Decode the model's text as JSON (tolerating a Markdown code fence) and validate it.
     *
     * @param  array<string, mixed>  $schema
     * @return array{array<string, mixed>|null, list<string>}
     */
    private function parse(string $text, array $schema): array
    {
        $json = trim($text);

        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $json, $match) === 1) {
            $json = $match[1];
        }

        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [null, ['the output is not valid JSON']];
        }

        if (! is_array($data)) {
            return [null, ['the output must be a JSON object']];
        }

        return [$data, $this->validator->validate($data, $schema)];
    }

    /**
     * Check the budget and record the run with its estimated cost, atomically per workspace, so
     * concurrent calls near the ceiling overshoot by at most one call.
     *
     * With an idempotency key, the stored result of a succeeded run is returned instead. A key
     * whose run failed, or whose result has expired, is taken over by the new run; the earlier
     * run is kept without its key. The workspace lock makes this safe against a concurrent call.
     *
     * @throws GenerationInProgress
     */
    private function reserve(TaskRoute $route, Prompt $prompt, LlmRequest $request, ?User $actor, ?string $purpose, ?string $idempotencyKey): GenerationRun|GenerationResult
    {
        $estimate = $this->budget->estimate($route, $request);

        return DB::transaction(function () use ($route, $prompt, $request, $actor, $purpose, $idempotencyKey, $estimate): GenerationRun|GenerationResult {
            $workspace = Workspace::query()->whereKey($this->tenant->workspaceId())->lockForUpdate()->firstOrFail();
            $previous = $idempotencyKey === null ? null : GenerationRun::query()->where('idempotency_key', $idempotencyKey)->with('payload')->first();

            if ($previous !== null) {
                if ($previous->status === 'succeeded' && $previous->payload !== null) {
                    return new GenerationResult($previous, $previous->payload->output, (string) $previous->payload->response);
                }

                $this->release($previous);
            }

            $this->budget->ensureAffordable($workspace, $estimate);

            $run = new GenerationRun;
            $run->forceFill([
                'task' => $route->task,
                'purpose' => $purpose,
                'prompt_key' => $prompt->key,
                'prompt_version' => $prompt->version,
                'provider' => $route->provider,
                'model' => $route->model,
                'request_hash' => hash('sha256', (string) json_encode([$request->model, $request->system, $request->messages, $request->schema])),
                'idempotency_key' => $idempotencyKey,
                'cost_micros' => $estimate,
                'status' => 'running',
                'created_by' => $actor?->id,
            ])->save();

            return $run;
        });
    }

    /**
     * @param  list<string>  $responses
     * @param  array<string, mixed>|null  $data
     */
    private function succeed(GenerationRun $run, TaskRoute $route, LlmRequest $request, array $responses, ?array $data, string $text, int $inputTokens, int $outputTokens, int $latency, int $attempts): GenerationResult
    {
        $this->finish($run, $route, $request, $responses, $data, 'succeeded', $inputTokens, $outputTokens, $latency, $attempts);

        return new GenerationResult($run, $data, $text);
    }

    /**
     * @param  list<string>  $responses
     * @param  array<string, mixed>|null  $data
     */
    private function finish(GenerationRun $run, TaskRoute $route, LlmRequest $request, array $responses, ?array $data, string $status, int $inputTokens, int $outputTokens, int $latency, int $attempts, ?string $errorCode = null, ?string $errorMessage = null): void
    {
        DB::transaction(function () use ($run, $route, $request, $responses, $data, $status, $inputTokens, $outputTokens, $latency, $attempts, $errorCode, $errorMessage): void {
            $run->forceFill([
                'status' => $status,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'cost_micros' => $this->budget->cost($route, $inputTokens, $outputTokens),
                'latency_ms' => $latency,
                'attempts' => $attempts,
                'error_code' => $errorCode,
                'error_message' => $errorMessage === null ? null : mb_strimwidth($errorMessage, 0, 1000, '…'),
            ])->save();

            $payload = new GenerationPayload;
            $payload->forceFill([
                'generation_run_id' => $run->id,
                'request' => ['model' => $request->model, 'system' => $request->system, 'messages' => $request->messages],
                'response' => implode("\n\n--- next attempt ---\n\n", $responses),
                'output' => $data,
                'expires_at' => now()->addDays((int) config('ai.payload_retention_days')),
            ])->save();
        });
    }

    /**
     * Free an earlier run's idempotency key for a new attempt. A run still running is refused
     * until it is older than `ai.run_stale_after_minutes`; then it is recorded as abandoned.
     *
     * @throws GenerationInProgress
     */
    private function release(GenerationRun $previous): void
    {
        $abandoned = $previous->status === 'running';

        if ($abandoned && $previous->created_at?->isAfter(now()->subMinutes((int) config('ai.run_stale_after_minutes')))) {
            throw new GenerationInProgress(__('This is already being generated. Try again in a few minutes.'));
        }

        $previous->forceFill([
            'idempotency_key' => null,
            ...($abandoned ? ['status' => 'failed', 'error_code' => 'abandoned', 'error_message' => __('Never finished; a later call with the same key ran instead.')] : []),
        ])->save();
    }
}
