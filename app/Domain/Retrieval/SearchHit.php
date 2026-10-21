<?php

namespace App\Domain\Retrieval;

/**
 * One retrieved unit with the signals that ranked it.
 */
final readonly class SearchHit
{
    /**
     * @param  list<float>|null  $vector  the unit's embedding (for de-duplication), when semantic search ran
     */
    public function __construct(
        public RetrievalUnit $unit,
        public float $rrf,
        public ?int $semanticRank,
        public ?float $similarity,
        public ?int $lexicalRank,
        public float $score = 0.0,
        public ?array $vector = null,
    ) {}

    public function withScore(float $score): self
    {
        return new self($this->unit, $this->rrf, $this->semanticRank, $this->similarity, $this->lexicalRank, $score, $this->vector);
    }
}
