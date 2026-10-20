<?php

namespace App\Policies;

use App\Domain\Knowledge\AnswerLibraryEntry;
use App\Domain\Knowledge\AnswerStatus;
use App\Models\User;

/**
 * The candidate edits any answer that is not archived; a VA edits drafts and suggests changes
 * to approved answers (approved through Suggestions). Only the candidate approves, archives
 * and restores.
 */
class AnswerLibraryEntryPolicy
{
    public function update(User $user, AnswerLibraryEntry $entry): bool
    {
        if ($user->isCandidate()) {
            return $entry->status !== AnswerStatus::Archived;
        }

        return $entry->status === AnswerStatus::Draft;
    }

    public function propose(User $user, AnswerLibraryEntry $entry): bool
    {
        return ! $user->isCandidate() && $entry->status === AnswerStatus::Approved;
    }

    public function approve(User $user, AnswerLibraryEntry $entry): bool
    {
        return $user->isCandidate() && $entry->status === AnswerStatus::Draft;
    }

    public function archive(User $user, AnswerLibraryEntry $entry): bool
    {
        return $user->isCandidate() && $entry->status !== AnswerStatus::Archived;
    }

    public function restore(User $user, AnswerLibraryEntry $entry): bool
    {
        return $user->isCandidate() && $entry->status === AnswerStatus::Archived;
    }
}
