<?php

namespace App\Domain\Applications;

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
 * @property int $application_resume_id
 * @property int $version_no
 * @property array<string, mixed> $document
 * @property array<string, mixed> $facts_snapshot
 * @property string $author_type
 * @property int|null $author_user_id
 * @property string $created_via
 * @property int|null $parent_version_id
 * @property int|null $generation_run_id
 * @property array{refused: list<array{item: string, what: string, reason: string, kept: ?string}>, notes: list<string>}|null $ai_report what the server kept and refused from an AI proposal
 * @property CarbonImmutable $created_at
 * @property-read User|null $author
 */
#[Fillable(['workspace_id', 'application_resume_id', 'version_no', 'document', 'facts_snapshot', 'author_type', 'author_user_id', 'created_via', 'parent_version_id', 'generation_run_id', 'ai_report'])]
class ApplicationResumeVersion extends Model
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
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'document' => 'array',
            'facts_snapshot' => 'array',
            'ai_report' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
