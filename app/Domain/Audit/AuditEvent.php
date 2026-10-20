<?php

namespace App\Domain\Audit;

use App\Domain\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Append-only activity record (a database trigger rejects updates). Never stores content bodies.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $actor_user_id
 * @property string $actor_type
 * @property string $action
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property array<string, mixed> $metadata
 * @property string|null $ip_hash
 * @property Carbon $created_at
 */
#[Fillable(['workspace_id', 'actor_user_id', 'actor_type', 'action', 'subject_type', 'subject_id', 'metadata', 'ip_hash'])]
class AuditEvent extends Model
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
            'metadata' => 'array',
        ];
    }
}
