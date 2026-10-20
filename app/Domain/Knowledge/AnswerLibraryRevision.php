<?php

namespace App\Domain\Knowledge;

use App\Domain\Tenancy\BelongsToWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only (a database trigger rejects updates).
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $answer_library_entry_id
 * @property int $revision_no
 * @property string $body
 * @property list<string> $claim_ulids
 * @property string|null $change_reason
 * @property int|null $created_by
 * @property string $created_via
 * @property CarbonImmutable $created_at
 */
#[Fillable(['workspace_id', 'answer_library_entry_id', 'revision_no', 'body', 'claim_ulids', 'change_reason', 'created_by', 'created_via'])]
class AnswerLibraryRevision extends Model
{
    use BelongsToWorkspace;

    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'claim_ulids' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
