<?php

namespace App\Policies;

use App\Domain\Knowledge\Claim;
use App\Domain\Knowledge\ClaimStatus;
use App\Models\User;

/**
 * The candidate edits live claims; a VA edits only proposed ones (D5). A VA's change to a
 * verified claim becomes a `revise_claim` proposal instead (`propose`). Only the candidate verifies,
 * rejects, archives and restores.
 */
class ClaimPolicy
{
    public function update(User $user, Claim $claim): bool
    {
        if ($user->isCandidate()) {
            return in_array($claim->status, ClaimStatus::live(), true);
        }

        return $claim->status === ClaimStatus::Proposed;
    }

    /**
     * A VA suggests a change to a verified claim; the candidate approves it (ProposalService).
     */
    public function propose(User $user, Claim $claim): bool
    {
        return ! $user->isCandidate() && $claim->status === ClaimStatus::Verified;
    }

    public function verify(User $user, Claim $claim): bool
    {
        return $user->isCandidate() && $claim->status === ClaimStatus::Proposed;
    }

    public function reject(User $user, Claim $claim): bool
    {
        return $user->isCandidate() && $claim->status === ClaimStatus::Proposed;
    }

    public function archive(User $user, Claim $claim): bool
    {
        return $user->isCandidate() && in_array($claim->status, ClaimStatus::live(), true);
    }

    public function restore(User $user, Claim $claim): bool
    {
        return $user->isCandidate() && in_array($claim->status, [ClaimStatus::Archived, ClaimStatus::Rejected], true);
    }
}
