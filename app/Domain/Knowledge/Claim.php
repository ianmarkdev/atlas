<?php

namespace App\Domain\Knowledge;

use App\Domain\Retrieval\RetrievalSourceObserver;
use App\Domain\Shared\PlainText;
use App\Domain\Tenancy\BelongsToWorkspace;
use App\Policies\ClaimPolicy;
use Carbon\CarbonImmutable;
use Database\Factories\ClaimFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * The atomic, citable unit of career knowledge. The statement lives in append-only
 * revisions; `current_revision_id` points at the latest.
 *
 * @property int $id
 * @property string $ulid
 * @property int $workspace_id
 * @property int $candidate_profile_id
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property ClaimKind $kind
 * @property int|null $current_revision_id
 * @property ClaimStatus $status
 * @property string $origin
 * @property string $normalized_hash
 * @property int|null $verified_by
 * @property CarbonImmutable|null $verified_at
 * @property int|null $created_by
 * @property int $lock_version
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read ClaimRevision|null $currentRevision
 * @property-read Employment|Project|null $subject
 */
#[ObservedBy(RetrievalSourceObserver::class)]
#[UseFactory(ClaimFactory::class)]
#[UsePolicy(ClaimPolicy::class)]
class Claim extends Model
{
    /** @use HasFactory<ClaimFactory> */
    use BelongsToWorkspace, HasFactory, HasUlids;

    /**
     * Claims are written only through ClaimService, which sets every column deliberately.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /**
     * Hash of the statement with case, punctuation and spacing removed, so "Led a team of 4."
     * and "led a team of 4" count as the same claim.
     */
    public static function hashStatement(string $statement): string
    {
        $comparable = Str::of(PlainText::normalize($statement))
            ->lower()
            ->replaceMatches('/[^\pL\pN]+/u', ' ')
            ->trim()
            ->toString();

        return hash('sha256', $comparable);
    }

    /**
     * @return BelongsTo<ClaimRevision, $this>
     */
    public function currentRevision(): BelongsTo
    {
        return $this->belongsTo(ClaimRevision::class, 'current_revision_id');
    }

    /**
     * @return HasMany<ClaimRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(ClaimRevision::class)->orderBy('revision_no');
    }

    /**
     * @return HasMany<ClaimSource, $this>
     */
    public function sources(): HasMany
    {
        return $this->hasMany(ClaimSource::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function statement(): string
    {
        return $this->currentRevision->statement ?? '';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ClaimStatus::class,
            'kind' => ClaimKind::class,
            'verified_at' => 'datetime',
        ];
    }
}
