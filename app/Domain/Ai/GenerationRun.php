<?php

namespace App\Domain\Ai;

use App\Domain\Tenancy\BelongsToWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One AI call through AiGateway: usage, cost, latency and outcome (no text).
 *
 * @property int $id
 * @property string $ulid
 * @property int $workspace_id
 * @property string $task
 * @property string|null $purpose
 * @property string $prompt_key
 * @property int $prompt_version
 * @property string $provider
 * @property string $model
 * @property string $request_hash
 * @property string|null $idempotency_key
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $cost_micros
 * @property int $latency_ms
 * @property int $attempts
 * @property string $status
 * @property string|null $error_code
 * @property string|null $error_message
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read GenerationPayload|null $payload
 */
class GenerationRun extends Model
{
    use BelongsToWorkspace, HasUlids;

    /**
     * Written only through AiGateway.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    /**
     * @return HasOne<GenerationPayload, $this>
     */
    public function payload(): HasOne
    {
        return $this->hasOne(GenerationPayload::class);
    }
}
