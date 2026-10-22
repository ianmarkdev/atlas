<?php

namespace App\Domain\Applications;

use App\Domain\Knowledge\AnswerLibraryEntry;
use App\Domain\Tenancy\BelongsToWorkspace;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
 * @property array<string, array{ref: ?string, status: string, text: string}>|null $evidence_pack
 * @property list<array{text: string, classification: string, model_classification: string, evidence_ids: list<string>, flags: list<string>}>|null $grounding
 * @property list<array{question: string, why: string}>|null $clarification_questions
 * @property CarbonImmutable $created_at
 * @property-read User|null $author
 * @property-read AnswerLibraryEntry|null $libraryEntry
 */
#[Fillable(['workspace_id', 'answer_draft_id', 'version_no', 'body', 'library_entry_id', 'author_type', 'author_user_id', 'created_via', 'generation_run_id', 'evidence_pack', 'grounding', 'clarification_questions'])]
class AnswerDraftVersion extends Model
{
    use BelongsToWorkspace;

    public const UPDATED_AT = null;

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
