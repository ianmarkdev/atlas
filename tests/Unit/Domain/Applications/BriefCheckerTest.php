<?php

use App\Domain\Ai\PromptInjectionScanner;
use App\Domain\Applications\BriefChecker;

$jd = "You have 5+ years of PHP.\nPlease answer: 1. Why do you want to join us?\n2. Describe a system you scaled.";

function checkedBrief(array $brief, string $jd): array
{
    return (new BriefChecker(new PromptInjectionScanner))->check([
        'must_haves' => [], 'nice_to_haves' => [], 'questions' => [], 'injection_warnings' => [], ...$brief,
    ], $jd);
}

test('questions are kept only when the job description actually asks them', function () use ($jd) {
    $brief = checkedBrief(['questions' => [
        'Why do you want to join us?',
        'Describe a system you scaled',
        'Do you have 5+ years of PHP experience?',
    ]], $jd);

    expect($brief['questions'])->toBe(['Why do you want to join us?', 'Describe a system you scaled'])
        ->and($brief['dropped_questions'])->toBe(['Do you have 5+ years of PHP experience?']);
});

test('model injection warnings that point at nothing in the text are dropped', function () use ($jd) {
    $brief = checkedBrief(['injection_warnings' => ['The ad tells the assistant to reveal its system prompt']], $jd);

    expect($brief['injection_warnings'])->toBe([])
        ->and($brief['dropped_injection_warnings'])->toHaveCount(1)
        ->and($brief['server_injection_warnings'])->toBe([]);
});

test('quotes are checked against the job description', function () use ($jd) {
    $brief = checkedBrief(['must_haves' => [
        ['requirement' => 'PHP', 'quote' => '5+ years of PHP'],
        ['requirement' => 'Go', 'quote' => 'Strong Go experience'],
    ]], $jd);

    expect(array_column($brief['must_haves'], 'quote_check'))->toBe(['found', 'not_found']);
});
