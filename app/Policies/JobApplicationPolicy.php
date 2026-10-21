<?php

namespace App\Policies;

use App\Domain\Applications\JobApplication;
use App\Models\User;

/**
 * Candidate and VAs both add, edit and move applications (VAs prepare and send them for the
 * candidate). Only the candidate deletes or restores one.
 */
class JobApplicationPolicy
{
    public function update(User $user, JobApplication $application): bool
    {
        return ! $application->trashed();
    }

    public function delete(User $user, JobApplication $application): bool
    {
        return $user->isCandidate() && ! $application->trashed();
    }

    public function restore(User $user, JobApplication $application): bool
    {
        return $user->isCandidate() && $application->trashed();
    }
}
