<?php

namespace App\Policies;

use App\Domain\Knowledge\WritingPreference;
use App\Models\User;

/**
 * The candidate manages the rules for everyone; each user manages their personal ones. A VA's
 * rule for everyone becomes a suggestion for the candidate (WritingPreferenceService).
 */
class WritingPreferencePolicy
{
    public function archive(User $user, WritingPreference $preference): bool
    {
        if ($preference->status !== 'active') {
            return false;
        }

        return $preference->isPersonal() ? $preference->user_id === $user->id : $user->isCandidate();
    }
}
