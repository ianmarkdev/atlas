<?php

use App\Domain\Ai\ComparisonChecks;

$sample = [
    'variables' => ['limit' => 'at most 10 words'],
    'checks' => ['banned' => ['passionate', 'team player'], 'mentions' => ['Alex Morgan', 'Kind regards']],
];

test('an answer is measured against its limit, banned phrases and required mentions', function () use ($sample) {
    $checks = ComparisonChecks::of($sample, ['answer' => "I am Passionate about payments and cut a run by [confirm: %].\nKind regards, Alex"], []);

    expect($checks)->toBe([
        'word_count' => 15,
        'word_limit' => 10,
        'over_limit' => true,
        'placeholders' => 1,
        'banned_found' => ['passionate'],
        'missing' => ['Alex Morgan'],
        'corrected_claims' => 0,
    ]);
});

test('an answer within its limit that follows every rule passes every check', function () use ($sample) {
    $checks = ComparisonChecks::of($sample, ['answer' => 'Kind regards, Alex Morgan'], []);

    expect($checks)->toMatchArray(['over_limit' => false, 'banned_found' => [], 'missing' => [], 'placeholders' => 0]);
});

test('a sample without a word limit is never over it', function () {
    $checks = ComparisonChecks::of(['variables' => ['limit' => 'concise']], ['answer' => 'A short answer.'], []);

    expect($checks)->toMatchArray(['word_limit' => null, 'over_limit' => false]);
});

test('outputs without an answer only count the claims the server corrected', function () {
    $grounding = [['flags' => ['metric_without_source']], ['flags' => []]];

    expect(ComparisonChecks::of(['variables' => []], ['claims' => []], $grounding))->toBe(['corrected_claims' => 1])
        ->and(ComparisonChecks::of(['variables' => []], null, []))->toBe(['corrected_claims' => 0]);
});
