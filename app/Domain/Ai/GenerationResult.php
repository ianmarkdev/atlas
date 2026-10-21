<?php

namespace App\Domain\Ai;

/**
 * A successful, schema-valid AI result.
 */
final readonly class GenerationResult
{
    /**
     * @param  array<string, mixed>|null  $data  the validated JSON output (null for free-text prompts)
     */
    public function __construct(
        public GenerationRun $run,
        public ?array $data,
        public string $text,
    ) {}
}
