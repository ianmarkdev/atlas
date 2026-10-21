<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Hybrid retrieval (docs/04 §4.3, S3)
    |--------------------------------------------------------------------------
    |
    | Semantic top-N ∪ keyword top-N, fused with Reciprocal Rank Fusion, then
    | reranked in PHP. Weights are tuned with `php artisan eval:retrieval`.
    |
    */

    'candidates_per_signal' => 40,

    // Semantic matches below this cosine similarity are noise, not evidence (vector search always
    // returns the nearest items, related or not). Model-dependent: tune with the eval.
    'min_similarity' => (float) env('RETRIEVAL_MIN_SIMILARITY', 0.35),

    'fused_limit' => 30,
    'rrf_k' => 60,

    'weights' => [
        'rrf' => 1.0,
        'status' => 0.25,
        'specificity' => 0.15,
        'freshness' => 0.1,
    ],

    'status_boost' => [
        'verified' => 1.0,
        'approved' => 0.9,
        'proposed' => 0.4,
    ],

    // Years until a past item's freshness boost halves (current items get the full boost).
    'freshness_half_life_years' => 5,

    // Results closer than this (cosine similarity) to an already selected one are dropped.
    'duplicate_similarity' => 0.9,

];
