<?php

namespace App\Domain\Knowledge;

use App\Domain\Tenancy\BelongsToWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only (a database trigger rejects updates).
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $claim_id
 * @property int $revision_no
 * @property string $statement
 * @property array<string, mixed> $structured
 * @property string|null $change_reason
 * @property int|null $created_by
 * @property string $created_via
 * @property CarbonImmutable $created_at
 */
#[Fillable(['workspace_id', 'claim_id', 'revision_no', 'statement', 'change_reason', 'created_by', 'created_via'])]
class ClaimRevision extends Model
{
    use BelongsToWorkspace;

    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<Claim, $this>
     */
    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'structured' => 'array',
        ];
    }
}
