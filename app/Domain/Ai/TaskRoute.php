<?php

namespace App\Domain\Ai;

/**
 * Where one AI task runs and with which settings, resolved from config/ai.php.
 */
final readonly class TaskRoute
{
    public function __construct(
        public string $task,
        public LlmClient $client,
        public string $provider,
        public string $model,
        public int $maxTokens,
        public float $temperature,
        public int $timeoutSeconds,
        public bool $external,
    ) {}
}
