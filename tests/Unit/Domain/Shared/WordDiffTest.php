<?php

use App\Domain\Shared\WordDiff;

test('a change spanning several words is one deletion and one insertion', function () {
    $diff = WordDiff::compute('I am passionate about databases. Thanks', 'I optimise databases for a living. Thanks');

    expect(array_column($diff, 'op'))->toBe(['equal', 'delete', 'insert', 'equal'])
        ->and(WordDiff::render($diff))->toBe("- am passionate about databases.\n+ optimise databases for a living.");
});

test('the parts give back both texts exactly', function (string $from, string $to) {
    $diff = WordDiff::compute($from, $to);

    $side = fn (string $skip): string => implode('', array_column(array_filter($diff, fn (array $part): bool => $part['op'] !== $skip), 'text'));

    expect($side('insert'))->toBe($from)
        ->and($side('delete'))->toBe($to);
})->with([
    'added at the end' => ['Led a team', 'Led a team of five'],
    'line breaks kept' => ["First line.\n\nSecond line.", "First line.\nSecond, longer line."],
    'from empty' => ['', 'New text'],
]);

test('whitespace-only edits are not changes', function () {
    expect(WordDiff::hasChanges(WordDiff::compute('Led a  team', 'Led a team')))->toBeFalse()
        ->and(WordDiff::hasChanges(WordDiff::compute('Led a team', 'Led the team')))->toBeTrue();
});
