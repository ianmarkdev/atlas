<?php

namespace App\Domain\Knowledge;

enum WritingPreferenceKind: string
{
    case Tone = 'tone';
    case BannedPhrase = 'banned_phrase';
    case Format = 'format';
    case Spelling = 'spelling';
    case Structure = 'structure';

    public function label(): string
    {
        return match ($this) {
            self::Tone => 'Tone',
            self::BannedPhrase => 'Words to avoid',
            self::Format => 'Format',
            self::Spelling => 'Spelling',
            self::Structure => 'Structure',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $kind): array => ['value' => $kind->value, 'label' => $kind->label()], self::cases());
    }
}
