<?php

namespace App\Domain\Tenancy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * For every tenant table: scopes queries to the current workspace and fills
 * `workspace_id` from the tenant context on create when it is not set explicitly.
 *
 * @mixin Model
 */
trait BelongsToWorkspace
{
    public static function bootBelongsToWorkspace(): void
    {
        static::addGlobalScope(new WorkspaceScope);

        static::creating(function (Model $model): void {
            if ($model->getAttribute('workspace_id') === null) {
                $model->setAttribute('workspace_id', app(TenantContext::class)->workspaceId());
            }
        });
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
