<?php

namespace App\Domain\Applications;

/**
 * The preparation workflow between a VA and the candidate (D10). Transitions arrive with the
 * VA workflow (M7a slice D).
 */
enum ReviewStatus: string
{
    case NotStarted = 'not_started';
    case InProgress = 'in_progress';
    case InReview = 'in_review';
    case ChangesRequested = 'changes_requested';
    case Approved = 'approved';

    public function label(): string
    {
        return match ($this) {
            self::NotStarted => __('Not started'),
            self::InProgress => __('In progress'),
            self::InReview => __('In review'),
            self::ChangesRequested => __('Changes requested'),
            self::Approved => __('Approved'),
        };
    }
}
