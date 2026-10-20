<?php

namespace App\Policies;

use App\Domain\Knowledge\Employment;
use App\Domain\Knowledge\KnowledgeStatus;
use App\Domain\Knowledge\Project;
use App\Models\User;

/**
 * Employments and projects. The candidate edits anything not archived; a VA edits only
 * proposed records (D5: VAs never change verified facts directly). Only the candidate
 * verifies, archives and restores. Workspace isolation is handled by the tenant scope.
 */
class KnowledgeRecordPolicy
{
    public function update(User $user, Employment|Project $record): bool
    {
        if ($user->isCandidate()) {
            return $record->status !== KnowledgeStatus::Archived;
        }

        return $record->status === KnowledgeStatus::Proposed;
    }

    public function verify(User $user, Employment|Project $record): bool
    {
        return $user->isCandidate() && $record->status === KnowledgeStatus::Proposed;
    }

    public function archive(User $user, Employment|Project $record): bool
    {
        return $user->isCandidate() && $record->status !== KnowledgeStatus::Archived;
    }

    public function restore(User $user, Employment|Project $record): bool
    {
        return $user->isCandidate() && $record->status === KnowledgeStatus::Archived;
    }
}
