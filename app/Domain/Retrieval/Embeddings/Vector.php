<?php

namespace App\Domain\Retrieval\Embeddings;

/**
 * pgvector text literals for binding vectors as query parameters (`?::halfvec`).
 */
class Vector
{
    /**
     * @param  list<float>  $values
     */
    public static function literal(array $values): string
    {
        return '['.implode(',', array_map(fn (float $value): string => rtrim(rtrim(sprintf('%.6F', $value), '0'), '.') ?: '0', $values)).']';
    }
}
