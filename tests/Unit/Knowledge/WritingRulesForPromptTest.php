<?php

use App\Domain\Knowledge\WritingPreference;
use App\Domain\Knowledge\WritingPreferenceDrafts;
use App\Domain\Knowledge\WritingPreferenceKind;
use Illuminate\Database\Eloquent\Collection;

function promptRule(int $id, string $rule, string $via = 'proposal:1', int $seenIn = 0, int $missedIn = 0): WritingPreference
{
    return (new WritingPreference)->forceFill([
        'id' => $id,
        'rule' => $rule,
        'kind' => WritingPreferenceKind::Tone,
        'drafts' => WritingPreferenceDrafts::All,
        'created_via' => $via,
        'supporting_edits_count' => $seenIn,
        'missed_in_count' => $missedIn,
    ]);
}

test('rules the writer missed come first, then the best supported, a rule added by hand counting as two edits', function () {
    $rules = new Collection([
        promptRule(1, 'Oldest learned rule', seenIn: 1),
        promptRule(2, 'Added by hand', via: 'manual'),
        promptRule(3, 'Seen in three edits', seenIn: 3),
        promptRule(4, 'Missed once', seenIn: 1, missedIn: 1),
        promptRule(5, 'Newer learned rule', seenIn: 1),
    ]);

    expect(WritingPreference::forPrompt($rules, PHP_INT_MAX)->pluck('id')->all())->toBe([4, 3, 2, 1, 5]);
});

test('only the most important rules that fit are kept, a long one giving way to shorter ones after it', function () {
    $rules = new Collection([
        promptRule(1, 'Short rule', seenIn: 3),
        promptRule(2, str_repeat('A very long rule ', 10), seenIn: 2),
        promptRule(3, 'Another short rule', seenIn: 1),
        promptRule(4, 'Third short rule'),
    ]);
    $room = mb_strlen('- (Tone) Short rule') + mb_strlen('- (Tone) Another short rule') + 2;

    expect(WritingPreference::forPrompt($rules, $room)->pluck('id')->all())->toBe([1, 3]);
});
