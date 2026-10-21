<?php

namespace App\Domain\Ai;

/**
 * What a provider returned: the raw text (for structured requests, the JSON as text) and usage.
 */
final readonly class LlmResponse
{
    public function __construct(
        public string $text,
        public string $provider,
        public string $model,
        public int $inputTokens,
        public int $outputTokens,
        public int $latencyMs,
        public ?string $stopReason = null,
    ) {}
}
