<?php

namespace App\Domain\Ai;

use App\Domain\Tenancy\BelongsToWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * The prompt and response of one run, encrypted at rest and pruned after
 * `ai.payload_retention_days` (`php artisan model:prune`, scheduled daily).
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $generation_run_id
 * @property array<string, mixed> $request
 * @property string|null $response
 * @property array<string, mixed>|null $output
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable $created_at
 */
class GenerationPayload extends Model
{
    use BelongsToWorkspace, MassPrunable;

    public const UPDATED_AT = null;

    /**
     * Written only through AiGateway.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * Across all workspaces (pruning runs without a tenant context).
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return $this->newQueryWithoutScopes()->where('expires_at', '<', now());
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'request' => 'encrypted:array',
            'response' => 'encrypted',
            'output' => 'encrypted:array',
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
