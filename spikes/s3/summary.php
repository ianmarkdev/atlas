<?php

/** Prints the non-latency parts of a results file: distribution, sizes, build times, settings. */
$results = json_decode(file_get_contents(__DIR__."/results-{$argv[1]}.json"), true);

echo json_encode([
    'distribution' => $results['distribution'],
    'sizes' => $results['sizes'],
    'hnsw_build_ms' => $results['hnsw_build_ms'],
    'variant_build_ms' => $results['variant_build_ms'] ?? null,
    'server' => $results['server'],
    'plan_exact_big' => array_slice(explode("\n", $results['plans']['exact_big']), -3),
    'plan_hnsw_iterative_relaxed_big' => array_values(array_filter(explode("\n", $results['plans']['hnsw_iterative_relaxed_big']), fn ($l) => str_contains($l, 'Scan') || str_contains($l, 'Execution'))),
    'plan_hnsw_iterative_relaxed_small' => array_values(array_filter(explode("\n", $results['plans']['hnsw_iterative_relaxed_small']), fn ($l) => str_contains($l, 'Scan') || str_contains($l, 'Execution'))),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
