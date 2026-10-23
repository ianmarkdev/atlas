<?php

namespace App\Domain\Knowledge;

use App\Domain\Applications\ApplicationQuestion;

/**
 * Which drafts a writing preference shapes: all of them, or only cover letters or only answers
 * to application questions ("Sign off with my first name" means nothing in a 100-word answer).
 */
enum WritingPreferenceDrafts: string
{
    case All = 'all';
    case CoverLetters = 'cover_letter';
    case Questions = 'question';

    public function label(): string
    {
        return match ($this) {
            self::All => 'All drafts',
            self::CoverLetters => 'Cover letters only',
            self::Questions => 'Question answers only',
        };
    }

    public static function of(ApplicationQuestion $question): self
    {
        return $question->isCoverLetter() ? self::CoverLetters : self::Questions;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $drafts): array => ['value' => $drafts->value, 'label' => $drafts->label()], self::cases());
    }
}
