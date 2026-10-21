<?php

use App\Domain\Ai\ClaimGroundingValidator;

$evidence = [
    'E1' => ['status' => 'verified', 'text' => 'Cut the nightly invoice run from 4 hours to 20 minutes.'],
    'E2' => ['status' => 'proposed', 'text' => 'Led the PCI DSS scope reduction.'],
];

function groundOne(array $claim, array $evidence): array
{
    return (new ClaimGroundingValidator)->check([$claim], $evidence)[0];
}

test('a verified claim citing verified evidence with its own numbers stays verified', function () use ($evidence) {
    $result = groundOne(['text' => 'I cut the invoice run from 4 hours to 20 minutes.', 'classification' => 'verified', 'evidence_ids' => ['E1']], $evidence);

    expect($result['classification'])->toBe('verified')->and($result['flags'])->toBe([]);
});

test('verified is downgraded when the cited evidence is only proposed, or nothing is cited', function () use ($evidence) {
    expect(groundOne(['text' => 'I led the PCI work.', 'classification' => 'verified', 'evidence_ids' => ['E2']], $evidence))
        ->toMatchArray(['classification' => 'inferred', 'flags' => ['downgraded_no_verified_evidence']])
        ->and(groundOne(['text' => 'I am a great leader.', 'classification' => 'verified', 'evidence_ids' => []], $evidence))
        ->toMatchArray(['classification' => 'hypothetical', 'flags' => ['downgraded_no_verified_evidence']]);
});

test('an inferred claim without evidence becomes hypothetical', function () use ($evidence) {
    expect(groundOne(['text' => 'I would thrive here.', 'classification' => 'inferred', 'evidence_ids' => []], $evidence))
        ->toMatchArray(['classification' => 'hypothetical', 'flags' => ['downgraded_no_evidence']]);
});

test('a number that is not in the cited evidence turns the claim into one to confirm', function () use ($evidence) {
    expect(groundOne(['text' => 'I cut the invoice run by 95% and led 12 engineers.', 'classification' => 'verified', 'evidence_ids' => ['E1']], $evidence))
        ->toMatchArray(['classification' => 'proposed', 'model_classification' => 'verified', 'flags' => ['metric_without_source']]);
});

test('evidence ids that were not supplied are dropped, and unknown labels become hypothetical', function () use ($evidence) {
    expect(groundOne(['text' => 'I did it.', 'classification' => 'verified', 'evidence_ids' => ['E1', 'E99']], $evidence))
        ->toMatchArray(['evidence_ids' => ['E1'], 'flags' => ['unknown_evidence']])
        ->and(groundOne(['text' => 'I did it.', 'classification' => 'certain', 'evidence_ids' => []], $evidence)['classification'])
        ->toBe('hypothetical');
});
