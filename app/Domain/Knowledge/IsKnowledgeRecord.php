<?php

namespace App\Domain\Knowledge;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Shared by verifiable knowledge records (employments, projects): status, verification
 * stamp, optimistic lock version and ULID route keys.
 *
 * @property KnowledgeStatus $status
 * @property int $lock_version
 * @property int|null $verified_by
 * @property CarbonImmutable|null $verified_at
 * @property int|null $created_by
 *
 * @mixin Model
 */
trait IsKnowledgeRecord
{
    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    public function isVerified(): bool
    {
        return $this->status === KnowledgeStatus::Verified;
    }

    /**
     * @return BelongsTo<CandidateProfile, $this>
     */
    public function candidateProfile(): BelongsTo
    {
        return $this->belongsTo(CandidateProfile::class);
    }

    /**
     * Claims about this record, oldest first (the order they were entered).
     *
     * @return MorphMany<Claim, $this>
     */
    public function claims(): MorphMany
    {
        return $this->morphMany(Claim::class, 'subject')->oldest('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function notArchived(Builder $query): void
    {
        $query->where('status', '!=', KnowledgeStatus::Archived);
    }
}
