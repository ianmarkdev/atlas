<?php

namespace App\Domain\Ai;

use RuntimeException;

/**
 * The model's output was not valid JSON for the prompt's schema, even after the repair retry.
 */
class InvalidModelOutput extends RuntimeException
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(string $message, public array $errors = [], public ?int $generationRunId = null)
    {
        parent::__construct($message);
    }
}
