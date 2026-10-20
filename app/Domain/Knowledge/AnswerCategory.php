<?php

namespace App\Domain\Knowledge;

enum AnswerCategory: string
{
    case Motivation = 'motivation';
    case Strengths = 'strengths';
    case Weaknesses = 'weaknesses';
    case Behavioural = 'behavioural';
    case Technical = 'technical';
    case Salary = 'salary';
    case Availability = 'availability';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Motivation => 'Motivation',
            self::Strengths => 'Strengths',
            self::Weaknesses => 'Weaknesses',
            self::Behavioural => 'Behavioural',
            self::Technical => 'Technical',
            self::Salary => 'Salary & notice',
            self::Availability => 'Availability & location',
            self::Other => 'Other',
        };
    }
}
