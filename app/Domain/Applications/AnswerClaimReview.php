<?php

namespace App\Domain\Applications;

use App\Domain\Knowledge\Claim;
use App\Domain\Knowledge\ClaimStatus;
use App\Domain\Tenancy\BelongsToWorkspace;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The decision on one unconfirmed claim or AI question of an AI draft (M9): kept as worded
 * (`accepted`, candidate only), turned into a bullet point (`added`), or skipped (`dismissed`,
 * questions only).
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $answer_draft_version_id
 * @property string $item_type claim|question
 * @property int $item_index
 * @property string $decision accepted|added|dismissed
 * @property int|null $claim_id
 * @property int|null $decided_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Claim|null $claim
 */
class AnswerClaimReview extends Model
{
    use BelongsToWorkspace;

    /**
     * Written only through ClaimReviewService.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * Whether this decision settles a claim for the approval gate: kept by the candidate, or
     * turned into a bullet point that is verified (a VA's proposed one still needs the candidate).
     */
    public function settlesClaim(): bool
    {
        return $this->decision === 'accepted'
            || ($this->decision === 'added' && $this->claim?->status === ClaimStatus::Verified);
    }

    /**
     * @return BelongsTo<Claim, $this>
     */
    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
