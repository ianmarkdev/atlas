<?php

use App\Domain\Applications\StyleLint;

test('stock phrases are found in any paragraph, whatever their case', function () {
    $findings = StyleLint::findings("Dear Hiring Team,\n\nI have a Proven track record of shipping.");

    expect($findings)->toBe([
        ['paragraph' => 1, 'kind' => 'stock_phrase', 'text' => 'proven track record', 'fix' => 'Say it plainly, without "proven track record".'],
    ]);
});

test('a paragraph opening with a filler transition is found', function () {
    $findings = StyleLint::findings('Additionally, I designed webhook delivery with retries and signatures for partners.');

    expect($findings)->toBe([
        ['paragraph' => 0, 'kind' => 'filler_opening', 'text' => 'Additionally', 'fix' => 'Start this paragraph without "Additionally".'],
    ]);
});

test('a paragraph opening with the same word as an earlier one is found, except for "I"', function () {
    $body = "At Northwind Payments I cut the nightly invoice run to twenty minutes.\n\n"
        ."I tuned PostgreSQL indexes and halved the p95 latency of the API.\n\n"
        ."I mentored two junior developers through their first production releases.\n\n"
        .'At Contoso Retail I migrated the order service to Laravel without downtime.';

    expect(StyleLint::findings($body))->toBe([
        ['paragraph' => 3, 'kind' => 'repeated_opening', 'text' => 'At', 'fix' => 'Start this paragraph differently from paragraph 1.'],
    ]);
});

test('the opening of a short paragraph such as a greeting or sign-off is not checked', function () {
    expect(StyleLint::findings("Also,\n\nBest regards,\nAlex Morgan\n\nBest regards,\nAlex"))->toBe([]);
});

test('a paragraph with more than four sentences or ninety words is found as too long', function (string $paragraph, string $text) {
    expect(StyleLint::findings($paragraph))->toBe([
        ['paragraph' => 0, 'kind' => 'long_paragraph', 'text' => $text, 'fix' => 'Cut this paragraph to at most 4 sentences and 90 words, keeping its main point.'],
    ]);
})->with([
    'five sentences' => ['I built the billing API. I tuned its queries. I wrote its tests. I set up its CI. I ran its on-call rota.', '5 sentences'],
    'ninety-one words' => ['I built '.implode(' ', array_fill(0, 89, 'things')).'.', '91 words'],
]);

test('a paragraph of four sentences is not too long, whatever abbreviations it holds', function () {
    expect(StyleLint::findings('I work 9 a.m. to 5 p.m. on weekdays. I built the billing API. I tuned its queries. I wrote its tests.'))->toBe([]);
});
