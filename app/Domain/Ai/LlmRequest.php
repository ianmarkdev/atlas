<?php

namespace App\Domain\Ai;

/**
 * A provider-neutral chat request. With a schema the client asks for JSON matching it (Ollama
 * `format`, Claude forced tool use); the gateway still validates the result.
 */
final readonly class LlmRequest
{
    /**
     * @param  list<array{role: 'user'|'assistant', content: string}>  $messages
     * @param  array<string, mixed>|null  $schema  JSON Schema of the expected output
     */
    public function __construct(
        public string $model,
        public string $system,
        public array $messages,
        public int $maxTokens,
        public float $temperature = 0.0,
        public int $timeoutSeconds = 120,
        public ?array $schema = null,
    ) {}

    /**
     * The same request with one more turn (used for the repair retry).
     *
     * @param  array{role: 'user'|'assistant', content: string}  ...$turns
     */
    public function withTurns(array ...$turns): self
    {
        return new self($this->model, $this->system, [...$this->messages, ...array_values($turns)], $this->maxTokens, $this->temperature, $this->timeoutSeconds, $this->schema);
    }
}
