<?php

namespace App\Policies;

use App\Domain\Knowledge\Claim;
use App\Domain\Knowledge\ClaimStatus;
use App\Models\User;

/**
 * The candidate edits live claims; a VA edits only proposed ones (D5). Changing a verified
 * claim as a VA becomes a `revise_claim` proposal (M3 slice 4). Only the candidate verifies,
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
