<?php

namespace App\Domain\Retrieval\Embeddings;

use RuntimeException;

/**
 * The embedding provider could not be reached or returned something unusable.
 */
class EmbeddingFailed extends RuntimeException {}
