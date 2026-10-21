<?php

namespace App\Domain\Retrieval\Embeddings;

/**
 * Deterministic, offline vectors: words (lower-cased, crudely stemmed) are hashed into the
 * dimensions and the result is L2-normalised. Texts sharing words get similar vectors, which is
 * enough for tests and for working without Ollama; it is not semantic.
 */
class FakeEmbeddingProvider implements EmbeddingProvider
{
    public function __construct(private int $dimensions = 1024) {}

    public function embedDocuments(array $texts): array
    {
        return array_map($this->vector(...), $texts);
    }

    public function embedQuery(string $text): array
    {
        return $this->vector($text);
    }

    public function spec(): array
    {
        return ['provider' => 'fake', 'model' => 'word-hash', 'dimensions' => $this->dimensions];
    }

    /**
     * @return list<float>
     */
    private function vector(string $text): array
    {
        $vector = array_fill(0, $this->dimensions, 0.0);
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($text), $matches);

        foreach ($matches[0] as $word) {
            $stem = preg_replace('/(?:ing|ed|es|s)$/u', '', $word) ?: $word;
            $hash = crc32($stem);
            $vector[$hash % $this->dimensions] += ($hash & 1) === 1 ? 1.0 : -1.0;
        }

        $norm = sqrt(array_sum(array_map(fn (float $value): float => $value * $value, $vector)));

        if ($norm == 0.0) {
            $vector[0] = 1.0;

            return array_values($vector);
        }

        return array_values(array_map(fn (float $value): float => $value / $norm, $vector));
    }
}
