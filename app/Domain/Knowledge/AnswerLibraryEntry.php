<?php

namespace App\Domain\Knowledge;

use App\Domain\Tenancy\BelongsToWorkspace;
use App\Policies\AnswerLibraryEntryPolicy;
use Carbon\CarbonImmutable;
use Database\Factories\AnswerLibraryEntryFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A standard application question with the candidate's answer. The answer text lives in
 * append-only revisions; `current_revision_id` points at the latest.
 *
 * @property int $id
 * @property string $ulid
 * @property int $workspace_id
 * @property int $candidate_profile_id
 * @property string $question
 * @property AnswerCategory $category
 * @property int|null $current_revision_id
 * @property AnswerStatus $status
 * @property int|null $approved_by
 * @property CarbonImmutable|null $approved_at
 * @property int|null $created_by
 * @property int $lock_version
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read AnswerLibraryRevision|null $currentRevision
 */
#[UseFactory(AnswerLibraryEntryFactory::class)]
#[UsePolicy(AnswerLibraryEntryPolicy::class)]
class AnswerLibraryEntry extends Model
{
    /** @use HasFactory<AnswerLibraryEntryFactory> */
    use BelongsToWorkspace, HasFactory, HasUlids;

    /**
     * Written only through AnswerLibraryService.
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

    public function body(): string
    {
        return $this->currentRevision->body ?? '';
    }

    /**
     * @return list<string>
     */
    public function claimUlids(): array
    {
        return $this->currentRevision->claim_ulids ?? [];
    }

    /**
     * @return BelongsTo<AnswerLibraryRevision, $this>
     */
    public function currentRevision(): BelongsTo
    {
        return $this->belongsTo(AnswerLibraryRevision::class, 'current_revision_id');
    }

    /**
     * @return HasMany<AnswerLibraryRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(AnswerLibraryRevision::class)->orderByDesc('revision_no');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => AnswerCategory::class,
            'status' => AnswerStatus::class,
            'approved_at' => 'datetime',
        ];
    }
}
