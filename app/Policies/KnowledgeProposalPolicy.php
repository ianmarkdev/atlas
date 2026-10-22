<?php

namespace App\Policies;

use App\Domain\Knowledge\KnowledgeProposal;
use App\Models\User;

/**
 * Facts, library answers and rules for everyone are the candidate's call (D5); a VA never
 * decides them. A personal writing preference learned from a user's edits is decided by that
 * user alone (docs/04 §4.7 routing).
 */
class KnowledgeProposalPolicy
{
    public function decide(User $user, KnowledgeProposal $proposal): bool
    {
        return $proposal->reviewer_user_id === null
            ? $user->isCandidate()
            : $proposal->reviewer_user_id === $user->id;
    }
}
