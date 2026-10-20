<?php

use Illuminate\Support\Facades\DB;

test('pgvector computes cosine distance after migrating', function () {
    $distance = DB::scalar("SELECT '[1,0]'::vector <=> '[0,1]'::vector");

    expect((float) $distance)->toBe(1.0);
});

test('pg_trgm scores near-duplicate statements as similar after migrating', function () {
    $similarity = DB::scalar("SELECT similarity('Built a Laravel API', 'Built the Laravel API')");

    expect((float) $similarity)->toBeGreaterThan(0.6);
});

test('citext compares case-insensitively after migrating', function () {
    $isEqual = DB::scalar("SELECT 'Alex@Example.com'::citext = 'alex@example.com'::citext");

    expect($isEqual)->toBeTrue();
});
