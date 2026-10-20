<?php

namespace App\Domain\Knowledge;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Finds live claims that already say (nearly) the same thing. Exact duplicates match on
 * `normalized_hash`; near duplicates use pg_trgm similarity within the same subject.
 * Embedding similarity is added with retrieval (M6).
 */
class DuplicateClaimDetector
{
    /** Trigram similarity above which two statements count as near duplicates (tune in eval). */
    public const NEAR_DUPLICATE_THRESHOLD = 0.6;

    public function exactDuplicate(int $candidateProfileId, string $statement, ?int $exceptClaimId = null): ?Claim
    {
        return Claim::query()
            ->where('candidate_profile_id', $candidateProfileId)
            ->whereIn('status', ClaimStatus::live())
            ->where('normalized_hash', Claim::hashStatement($statement))
            ->when($exceptClaimId !== null, fn ($query) => $query->whereKeyNot($exceptClaimId))
            ->with('currentRevision')
            ->first();
    }

    /**
     * @return Collection<int, Claim>
     */
    public function nearDuplicates(int $candidateProfileId, string $statement, ?Model $subject, ?int $exceptClaimId = null): Collection
    {
        return Claim::query()
            ->select('claims.*')
            ->join('claim_revisions', 'claim_revisions.id', '=', 'claims.current_revision_id')
            ->where('claims.candidate_profile_id', $candidateProfileId)
            ->whereIn('claims.status', ClaimStatus::live())
            ->where('claims.subject_type', $subject?->getMorphClass())
            ->where('claims.subject_id', $subject?->getKey())
            ->whereRaw('similarity(claim_revisions.statement, ?) > ?', [$statement, self::NEAR_DUPLICATE_THRESHOLD])
            ->when($exceptClaimId !== null, fn ($query) => $query->where('claims.id', '!=', $exceptClaimId))
            ->orderByRaw('similarity(claim_revisions.statement, ?) DESC', [$statement])
            ->limit(3)
            ->with('currentRevision')
            ->get();
    }
}
