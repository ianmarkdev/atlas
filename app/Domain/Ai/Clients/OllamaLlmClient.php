<?php

namespace App\Domain\Ai\Clients;

use App\Domain\Ai\LlmClient;
use App\Domain\Ai\LlmConfigurationError;
use App\Domain\Ai\LlmRequest;
use App\Domain\Ai\LlmResponse;
use App\Domain\Ai\LlmUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * A local Ollama server (the default, D12): `POST /api/chat`, non-streaming. A schema is passed
 * as `format`, which constrains the output to JSON of that shape. Nothing leaves the machine.
 */
class OllamaLlmClient implements LlmClient
{
    public function __construct(private string $url, private string $keepAlive = '10m') {}

    public function complete(LlmRequest $request): LlmResponse
    {
        $started = hrtime(true);

        try {
            $response = ProviderRetry::run(fn () => Http::baseUrl($this->url)
                ->timeout($request->timeoutSeconds)
                ->connectTimeout(5)
                ->acceptJson()
                ->post('/api/chat', array_filter([
                    'model' => $request->model,
                    'messages' => [['role' => 'system', 'content' => $request->system], ...$request->messages],
                    'stream' => false,
                    'format' => $request->schema,
                    'keep_alive' => $this->keepAlive,
                    // Without num_ctx Ollama uses its default window and silently drops the start
                    // of a longer prompt (the instructions first).
                    'options' => array_filter(
                        ['temperature' => $request->temperature, 'num_predict' => $request->maxTokens, 'num_ctx' => $request->contextTokens],
                        fn (mixed $value): bool => $value !== null,
                    ),
                ], fn (mixed $value): bool => $value !== null)));
        } catch (ConnectionException $exception) {
            throw new LlmUnavailable("Ollama is not reachable at {$this->url}. Is it running? (ollama serve)", previous: $exception);
        }

        if ($response->status() === 404) {
            throw new LlmConfigurationError("Ollama does not have the model \"{$request->model}\". Run: ollama pull {$request->model}");
        }

        if ($response->failed()) {
            throw new LlmUnavailable("Ollama failed with HTTP {$response->status()}: ".mb_strimwidth((string) $response->json('error', $response->body()), 0, 300, '…'));
        }

        return new LlmResponse(
            text: (string) $response->json('message.content', ''),
            provider: 'ollama',
            model: (string) $response->json('model', $request->model),
            inputTokens: (int) $response->json('prompt_eval_count', 0),
            outputTokens: (int) $response->json('eval_count', 0),
            latencyMs: (int) round((hrtime(true) - $started) / 1e6),
            stopReason: $response->json('done_reason'),
        );
    }

    public function provider(): string
    {
        return 'ollama';
    }
}
