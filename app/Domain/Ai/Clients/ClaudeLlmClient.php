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
 * Optional: the Anthropic Messages API through Laravel's HTTP client (no SDK dependency).
 * Structured output is requested by forcing a single tool whose input schema is the prompt's
 * schema; the tool input is returned as JSON text. The API key never leaves the server.
 */
class ClaudeLlmClient implements LlmClient
{
    private const OUTPUT_TOOL = 'submit_output';

    public function __construct(private string $url, private ?string $apiKey, private string $version) {}

    public function complete(LlmRequest $request): LlmResponse
    {
        if ($this->apiKey === null || trim($this->apiKey) === '') {
            throw new LlmConfigurationError('Claude is selected but ANTHROPIC_API_KEY is not set. Set it in .env, or route the task to Ollama (AI_PROVIDER=ollama).');
        }

        $body = [
            'model' => $request->model,
            'max_tokens' => $request->maxTokens,
            'system' => $request->system,
            'messages' => $request->messages,
            'temperature' => $request->temperature,
        ];

        if ($request->schema !== null) {
            $body['tools'] = [[
                'name' => self::OUTPUT_TOOL,
                'description' => 'Return the result in exactly this structure.',
                'input_schema' => $request->schema,
            ]];
            $body['tool_choice'] = ['type' => 'tool', 'name' => self::OUTPUT_TOOL];
        }

        $started = hrtime(true);

        try {
            $response = ProviderRetry::run(fn () => Http::baseUrl($this->url)
                ->timeout($request->timeoutSeconds)
                ->connectTimeout(10)
                ->withHeaders(['x-api-key' => $this->apiKey, 'anthropic-version' => $this->version])
                ->acceptJson()
                ->post('/v1/messages', $body));
        } catch (ConnectionException $exception) {
            throw new LlmUnavailable('The Claude API is not reachable.', previous: $exception);
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw new LlmConfigurationError('The Claude API rejected the API key (HTTP '.$response->status().'). Check ANTHROPIC_API_KEY.');
        }

        if ($response->failed()) {
            throw new LlmUnavailable("The Claude API failed with HTTP {$response->status()}: ".mb_strimwidth((string) $response->json('error.message', $response->body()), 0, 300, '…'));
        }

        return new LlmResponse(
            text: $this->textOf((array) $response->json('content', []), $request->schema !== null),
            provider: 'claude',
            model: (string) $response->json('model', $request->model),
            inputTokens: (int) $response->json('usage.input_tokens', 0),
            outputTokens: (int) $response->json('usage.output_tokens', 0),
            latencyMs: (int) round((hrtime(true) - $started) / 1e6),
            stopReason: $response->json('stop_reason'),
        );
    }

    public function provider(): string
    {
        return 'claude';
    }

    /**
     * @param  array<int, mixed>  $blocks
     */
    private function textOf(array $blocks, bool $structured): string
    {
        foreach ($blocks as $block) {
            if ($structured && is_array($block) && ($block['type'] ?? null) === 'tool_use' && ($block['name'] ?? null) === self::OUTPUT_TOOL) {
                return (string) json_encode($block['input'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        $text = '';

        foreach ($blocks as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text') {
                $text .= (string) ($block['text'] ?? '');
            }
        }

        return $text;
    }
}
