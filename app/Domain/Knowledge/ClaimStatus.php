<?php

namespace App\Domain\Knowledge;

/**
 * Only `Verified` claims can back a `verified` label in generated text. `Rejected` is a
 * proposed claim the candidate turned down.
 */
enum ClaimStatus: string
{
    case Verified = 'verified';
    case Proposed = 'proposed';
    case Rejected = 'rejected';
    case Archived = 'archived';

    /**
     * Statuses that count as "live" for duplicate detection and retrieval.
     *
     * @return list<self>
     */
    public static function live(): array
    {
        return [self::Verified, self::Proposed];
    }
}
