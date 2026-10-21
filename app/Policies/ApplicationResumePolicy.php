<?php

namespace App\Policies;

use App\Domain\Applications\ApplicationResume;
use App\Models\User;

/**
 * Candidate and VAs tailor the resume of an application; only the candidate approves, reopens
 * or writes it back to the resume it came from.
 */
class ApplicationResumePolicy
{
    public function update(User $user, ApplicationResume $resume): bool
    {
        return ! $resume->isApproved() && ! $resume->application->trashed();
    }

    public function approve(User $user, ApplicationResume $resume): bool
    {
        return $user->isCandidate() && ! $resume->isApproved();
    }

    public function reopen(User $user, ApplicationResume $resume): bool
    {
        return $user->isCandidate() && $resume->isApproved();
    }

    public function saveBack(User $user, ApplicationResume $resume): bool
    {
        return $user->isCandidate() && $resume->based_on_variant_id !== null;
    }
}
