<?php

namespace App\Domain\Applications;

/**
 * Where an application stands in the job search (the dashboard pipeline). Any stage can be
 * changed to any other: real searches move backwards too (an offer withdrawn, a re-applied job).
 */
enum ApplicationStage: string
{
    case Saved = 'saved';
    case Preparing = 'preparing';
    case Applied = 'applied';
    case Interviewing = 'interviewing';
    case Offer = 'offer';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Saved => __('Saved'),
            self::Preparing => __('Preparing'),
            self::Applied => __('Applied'),
            self::Interviewing => __('Interviewing'),
            self::Offer => __('Offer'),
            self::Rejected => __('Rejected'),
            self::Withdrawn => __('Withdrawn'),
        };
    }

    /**
     * Closed applications leave the board's columns and never count as overdue.
     */
    public function isClosed(): bool
    {
        return $this === self::Rejected || $this === self::Withdrawn;
    }

    /**
     * Whether the application has been sent to the employer.
     */
    public function isSubmitted(): bool
    {
        return in_array($this, [self::Applied, self::Interviewing, self::Offer], true);
    }
}
