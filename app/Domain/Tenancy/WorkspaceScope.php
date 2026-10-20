<?php

namespace App\Domain\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Restricts every query on a tenant model to the current workspace. Throws when no
 * workspace is set, so a missing context can never return another tenant's rows.
 *
 * @template TModel of Model
 *
 * @implements Scope<TModel>
 */
class WorkspaceScope implements Scope
{
    /**
     * @param  Builder<covariant TModel>  $builder
     * @param  TModel  $model
     */
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->qualifyColumn('workspace_id'), app(TenantContext::class)->workspaceId());
    }
}
