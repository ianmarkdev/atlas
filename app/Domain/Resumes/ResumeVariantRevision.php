<?php

namespace App\Domain\Resumes;

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
 * @property int $resume_variant_id
 * @property int $revision_no
 * @property array<string, mixed> $document
 * @property int|null $created_by
 * @property string $created_via
 * @property CarbonImmutable $created_at
 * @property-read User|null $author
 */
#[Fillable(['workspace_id', 'resume_variant_id', 'revision_no', 'document', 'created_by', 'created_via'])]
class ResumeVariantRevision extends Model
{
    use BelongsToWorkspace;

    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
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
            'created_at' => 'datetime',
        ];
    }
}
