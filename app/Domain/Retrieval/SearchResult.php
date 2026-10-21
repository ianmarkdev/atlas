<?php

namespace App\Domain\Retrieval;

/**
 * Ranked hits, and whether the semantic half ran (it is skipped when nothing has been embedded
 * yet or the embedding provider is down; keyword search still answers).
 */
final readonly class SearchResult
{
    /**
     * @param  list<SearchHit>  $hits
     */
    public function __construct(
        public array $hits,
        public bool $semantic,
        public ?string $semanticUnavailableReason = null,
    ) {}
}
