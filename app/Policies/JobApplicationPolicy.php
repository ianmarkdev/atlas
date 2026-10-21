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

    /**
     * The candidate assigns anyone; a VA takes an unassigned application or hands back their own.
     */
    public function assign(User $user, JobApplication $application, ?User $assignee = null): bool
    {
        if ($application->trashed()) {
            return false;
        }

        if ($user->isCandidate()) {
            return true;
        }

        return $assignee?->is($user)
            ? $application->assigned_user_id === null
            : $assignee === null && $application->assigned_user_id === $user->id;
    }

    /**
     * A VA hands the prepared application to the candidate.
     */
    public function submitForReview(User $user, JobApplication $application): bool
    {
        return ! $user->isCandidate() && ! $application->trashed();
    }

    /**
     * Request changes, approve and reopen are the candidate's.
     */
    public function review(User $user, JobApplication $application): bool
    {
        return $user->isCandidate() && ! $application->trashed();
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
