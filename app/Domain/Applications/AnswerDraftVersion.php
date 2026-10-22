<?php

namespace App\Domain\Applications;

use App\Domain\Knowledge\AnswerLibraryEntry;
use App\Domain\Tenancy\BelongsToWorkspace;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Append-only (a database trigger rejects updates).
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $answer_draft_id
 * @property int $version_no
 * @property string $body
 * @property int|null $library_entry_id
 * @property string $author_type
 * @property int|null $author_user_id
 * @property string $created_via
 * @property int|null $generation_run_id
 * @property string|null $instruction the requester's instruction for this AI draft
 * @property int|null $paragraph the paragraph (0-based) this AI version redrafted; null for a whole draft
 * @property int|null $resume_version_id the tailored resume version a cover letter was written from
 * @property array<string, array{ref: ?string, status: string, text: string}>|null $evidence_pack
 * @property list<array{text: string, classification: string, model_classification: string, evidence_ids: list<string>, flags: list<string>}>|null $grounding
 * @property list<array{question: string, why: string}>|null $clarification_questions
 * @property CarbonImmutable $created_at
 * @property-read User|null $author
 * @property-read ApplicationResumeVersion|null $resumeVersion
 * @property-read Collection<int, AnswerClaimReview> $reviews
 * @property-read AnswerLibraryEntry|null $libraryEntry
 */
#[Fillable(['workspace_id', 'answer_draft_id', 'version_no', 'body', 'library_entry_id', 'author_type', 'author_user_id', 'created_via', 'generation_run_id', 'instruction', 'paragraph', 'resume_version_id', 'evidence_pack', 'grounding', 'clarification_questions'])]
class AnswerDraftVersion extends Model
{
    use BelongsToWorkspace;

    public const UPDATED_AT = null;

    /**
     * Claims of an AI draft the candidate should confirm: labelled `proposed`, or lowered or
     * flagged by the server check. Hypothetical statements (approaches, opinions) are shown
     * with their label but need no confirmation.
     *
     * @return array<int, array{text: string, classification: string, model_classification: string, evidence_ids: list<string>, flags: list<string>}> keyed by their index in `grounding`
     */
    public function claimsToCheck(): array
    {
        return array_filter(
            $this->grounding ?? [],
            fn (array $claim): bool => $claim['classification'] === 'proposed' || $claim['flags'] !== [],
        );
    }

    /**
     * Claims to check that no decision has settled yet (M9).
     *
     * @return array<int, array{text: string, classification: string, model_classification: string, evidence_ids: list<string>, flags: list<string>}>
     */
    public function openClaims(): array
    {
        $settled = $this->reviews
            ->filter(fn (AnswerClaimReview $review): bool => $review->item_type === 'claim' && $review->settlesClaim())
            ->pluck('item_index')
            ->all();

        return array_diff_key($this->claimsToCheck(), array_flip($settled));
    }

    /**
     * @return HasMany<AnswerClaimReview, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(AnswerClaimReview::class);
    }

    /**
     * @return BelongsTo<ApplicationResumeVersion, $this>
     */
    public function resumeVersion(): BelongsTo
    {
        return $this->belongsTo(ApplicationResumeVersion::class, 'resume_version_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    /**
     * @return BelongsTo<AnswerLibraryEntry, $this>
     */
    public function libraryEntry(): BelongsTo
    {
        return $this->belongsTo(AnswerLibraryEntry::class, 'library_entry_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'evidence_pack' => 'array',
            'grounding' => 'array',
            'clarification_questions' => 'array',
        ];
    }
}
