<?php

namespace App\Domain\Retrieval\Embeddings;

/**
 * Turns text into vectors. Documents (library units) and queries are separate calls because
 * some models embed them differently; bge-m3 does not.
 */
interface EmbeddingProvider
{
    /**
     * @param  list<string>  $texts
     * @return list<list<float>> one unit-length vector per text, in order
     *
     * @throws EmbeddingFailed
     */
    public function embedDocuments(array $texts): array;

    /**
     * @return list<float>
     *
     * @throws EmbeddingFailed
     */
    public function embedQuery(string $text): array;

    /**
     * @return array{provider: string, model: string, dimensions: int}
     */
    public function spec(): array;
}
