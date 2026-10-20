<?php

namespace App\Domain\Resumes;

use App\Domain\Knowledge\CandidateProfile;
use App\Domain\Tenancy\BelongsToWorkspace;
use Carbon\CarbonImmutable;
use Database\Factories\ResumeVariantFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One resume of the candidate, e.g. "PHP focused". Its content is the resume document of
 * the current revision; facts are read from the library by ULID (D9, ADR-9).
 *
 * @property int $id
 * @property string $ulid
 * @property int $workspace_id
 * @property int $candidate_profile_id
 * @property string $name
 * @property bool $is_default
 * @property string $status
 * @property int|null $current_revision_id
 * @property int|null $created_by
 * @property int $lock_version
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read ResumeVariantRevision|null $currentRevision
 */
#[UseFactory(ResumeVariantFactory::class)]
class ResumeVariant extends Model
{
    /** @use HasFactory<ResumeVariantFactory> */
    use BelongsToWorkspace, HasFactory, HasUlids;

    /**
     * Written only through ResumeService.
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

    public function isArchived(): bool
    {
        return $this->status === 'archived';
    }

    /**
     * The current document, or an empty one for a resume without revisions.
     *
     * @return array<string, mixed>
     */
    public function document(): array
    {
        return $this->currentRevision->document ?? ResumeDocumentValidator::emptyDocument();
    }

    /**
     * @return BelongsTo<ResumeVariantRevision, $this>
     */
    public function currentRevision(): BelongsTo
    {
        return $this->belongsTo(ResumeVariantRevision::class, 'current_revision_id');
    }

    /**
     * @return HasMany<ResumeVariantRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(ResumeVariantRevision::class)->orderByDesc('revision_no');
    }

    /**
     * @return BelongsTo<CandidateProfile, $this>
     */
    public function candidateProfile(): BelongsTo
    {
        return $this->belongsTo(CandidateProfile::class);
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', 'active');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }
}
