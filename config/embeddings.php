<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Embedding provider (D11, ADR-10)
    |--------------------------------------------------------------------------
    |
    | "ollama" calls a local Ollama server (free, data stays on the machine).
    | "fake" builds deterministic word-hash vectors: for tests, and for working
    | without Ollama (keyword search still works; semantic search is rough).
    |
    */

    'provider' => env('EMBEDDINGS_PROVIDER', 'ollama'),

    'ollama' => [
        'url' => env('OLLAMA_URL', 'http://127.0.0.1:11434'),
        'model' => env('OLLAMA_EMBEDDING_MODEL', 'bge-m3'),
        'dimensions' => (int) env('OLLAMA_EMBEDDING_DIMENSIONS', 1024),
        'timeout' => (int) env('OLLAMA_TIMEOUT', 60),
    ],

    'fake' => [
        'dimensions' => 1024,
    ],

    // Texts per embedding request.
    'batch_size' => (int) env('EMBEDDINGS_BATCH_SIZE', 32),

];
