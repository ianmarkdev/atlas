<?php

namespace App\Domain\Ai;

use App\Domain\Ai\Clients\ClaudeLlmClient;
use App\Domain\Ai\Clients\FakeLlmClient;
use App\Domain\Ai\Clients\OllamaLlmClient;

/**
 * Picks the client and model for an AI task (`config('ai.tasks')`). Refuses, before anything
 * is sent, to route private data to an external provider unless AI_ALLOW_EXTERNAL is true.
 * Tests swap every route to a FakeLlmClient with `fake()`.
 */
class LlmRouter
{
    /** @var array<string, LlmClient> */
    private array $clients = [];

    private ?FakeLlmClient $fake = null;

    /**
     * Send every task to `$fake` (tests).
     */
    public function fake(FakeLlmClient $fake): FakeLlmClient
    {
        return $this->fake = $fake;
    }

    /**
     * @param  string|null  $provider  force a provider (comparison runs); null = the task's configured one
     *
     * @throws LlmConfigurationError
     */
    public function route(string $task, ?string $provider = null): TaskRoute
    {
        $settings = config("ai.tasks.{$task}");

        if (! is_array($settings)) {
            throw new LlmConfigurationError("Unknown AI task \"{$task}\". Add it to config/ai.php.");
        }

        $provider ??= $settings['provider'] ?: config('ai.default_provider');
        $model = $settings['models'][$provider] ?? null;

        if (! is_string($provider) || (! is_string($model) && $this->fake === null)) {
            throw new LlmConfigurationError("No model is configured for task \"{$task}\" on provider \"{$provider}\".");
        }

        $external = (bool) config("ai.providers.{$provider}.external", false);

        if ($external && $this->fake === null && ! config('ai.allow_external')) {
            throw new LlmConfigurationError("Task \"{$task}\" is routed to {$provider}, which would send your private career data off this machine. Set AI_ALLOW_EXTERNAL=true to allow it, or use Ollama.");
        }

        return new TaskRoute(
            task: $task,
            client: $this->fake ?? $this->client($provider),
            provider: $this->fake?->provider() ?? $provider,
            model: (string) ($model ?? 'fake-model'),
            maxTokens: (int) $settings['max_tokens'],
            temperature: (float) $settings['temperature'],
            timeoutSeconds: (int) $settings['timeout'],
            external: $this->fake === null && $external,
            contextTokens: isset($settings['context']) ? (int) $settings['context'] : null,
        );
    }

    private function client(string $provider): LlmClient
    {
        return $this->clients[$provider] ??= match ($provider) {
            'ollama' => new OllamaLlmClient((string) config('ai.providers.ollama.url'), (string) config('ai.providers.ollama.keep_alive')),
            'claude' => new ClaudeLlmClient(
                (string) config('ai.providers.claude.url'),
                config('ai.providers.claude.api_key'),
                (string) config('ai.providers.claude.version'),
            ),
            default => throw new LlmConfigurationError("Unknown AI provider \"{$provider}\". Use ollama or claude."),
        };
    }
}
