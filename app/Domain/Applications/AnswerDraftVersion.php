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
 * @property CarbonImmutable $created_at
 * @property-read User|null $author
 * @property-read AnswerLibraryEntry|null $libraryEntry
 */
#[Fillable(['workspace_id', 'answer_draft_id', 'version_no', 'body', 'library_entry_id', 'author_type', 'author_user_id', 'created_via'])]
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
        ];
    }
}
