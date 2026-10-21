<?php

namespace App\Policies;

use App\Domain\Applications\ApplicationQuestion;
use App\Models\User;

/**
 * Candidate and VAs add questions and write answers; only the candidate approves or reopens an
 * answer.
 */
class ApplicationQuestionPolicy
{
    public function update(User $user, ApplicationQuestion $question): bool
    {
        return ! $question->application->trashed();
    }

    public function approve(User $user, ApplicationQuestion $question): bool
    {
        return $user->isCandidate() && ! ($question->answer?->isApproved() ?? false);
    }

    public function reopen(User $user, ApplicationQuestion $question): bool
    {
        return $user->isCandidate() && ($question->answer?->isApproved() ?? false);
    }
}
