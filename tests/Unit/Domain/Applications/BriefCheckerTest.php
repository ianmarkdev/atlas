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

test('the items of a list introduced as what to send are asked, other lists are not', function () {
    $jd = "Responsibilities include:\n- Build Zoho workflows.\n\nHow to Apply\n\nPlease provide:\n\n1. Your experience integrating Twilio with Zoho.\n2. Your estimated timeline and project-based rate or quotation.";

    $brief = checkedBrief(['questions' => [
        'Your experience integrating Twilio with Zoho.',
        'Your estimated timeline and project-based rate or quotation.',
        'Build Zoho workflows.',
    ]], $jd);

    expect($brief['questions'])->toBe(['Your experience integrating Twilio with Zoho.', 'Your estimated timeline and project-based rate or quotation.'])
        ->and($brief['dropped_questions'])->toBe(['Build Zoho workflows.']);
});

test('an unbulleted list of what to send is asked up to the blank line after it, and a question merging it is split into its items', function () {
    $jd = "How to Apply\n\nPlease include:\n\nLinks to SaaS applications you have personally built.\nYour preferred technology stack.\nYour availability and preferred working arrangement.\n\nWe are an equal opportunity employer.";

    $brief = checkedBrief(['questions' => [
        'Please include: Links to SaaS applications you have personally built. Your preferred technology stack. Your availability and preferred working arrangement.',
        'Are you an equal opportunity employer?',
    ]], $jd);

    expect($brief['questions'])->toBe([
        'Links to SaaS applications you have personally built.',
        'Your preferred technology stack.',
        'Your availability and preferred working arrangement.',
    ])->and($brief['dropped_questions'])->toBe(['Are you an equal opportunity employer?']);
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
