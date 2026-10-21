<?php

use App\Domain\Ai\JsonSchemaValidator;

$schema = [
    'type' => 'object',
    'additionalProperties' => false,
    'required' => ['answer', 'claims'],
    'properties' => [
        'answer' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 20],
        'claims' => [
            'type' => 'array',
            'maxItems' => 2,
            'items' => [
                'type' => 'object',
                'required' => ['label'],
                'properties' => ['label' => ['type' => 'string', 'enum' => ['verified', 'hypothetical']]],
            ],
        ],
    ],
];

test('valid output has no errors', function () use ($schema) {
    expect((new JsonSchemaValidator)->validate(['answer' => 'Yes', 'claims' => [['label' => 'verified']]], $schema))->toBe([]);
});

test('missing, extra, mistyped, out-of-range and wrong-enum values are all reported with paths', function () use ($schema) {
    $errors = (new JsonSchemaValidator)->validate([
        'answer' => str_repeat('x', 21),
        'claims' => [['label' => 'certain'], ['label' => 1], []],
        'extra' => true,
    ], $schema);

    expect($errors)->toContain('$.answer: longer than 20 characters')
        ->toContain('$.claims: has more than 2 items')
        ->toContain('$.claims[0].label: must be one of "verified", "hypothetical"')
        ->toContain('$.claims[1].label: expected string, got integer')
        ->toContain('$.claims[2].label: is required')
        ->toContain('$.extra: is not allowed');

    expect((new JsonSchemaValidator)->validate(['claims' => []], $schema))->toContain('$.answer: is required');
});
