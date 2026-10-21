<?php

namespace App\Domain\Ai;

use App\Domain\Tenancy\BelongsToWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One provider's output for one comparison sample, with its human scores.
 *
 * @property int $id
 * @property string $ulid
 * @property int $workspace_id
 * @property string $batch
 * @property string $sample_key
 * @property string $task
 * @property string $provider
 * @property string|null $model
 * @property string $blind_label
 * @property string $status
 * @property array<string, mixed>|null $output
 * @property list<string> $validation_errors
 * @property list<array<string, mixed>> $grounding_flags
 * @property string|null $error_message
 * @property int|null $latency_ms
 * @property int|null $input_tokens
 * @property int|null $output_tokens
 * @property int|null $generation_run_id
 * @property int|null $score_accuracy
 * @property int|null $score_specificity
 * @property int|null $score_writing
 * @property int|null $score_compliance
 * @property string|null $notes
 * @property int|null $rated_by
 * @property CarbonImmutable|null $rated_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class LlmComparisonResult extends Model
{
    use BelongsToWorkspace, HasUlids;

    public const SCORES = ['accuracy', 'specificity', 'writing', 'compliance'];

    /**
     * Written by LlmComparison and the rating form.
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

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    public function isRated(): bool
    {
        return $this->rated_at !== null;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'output' => 'array',
            'validation_errors' => 'array',
            'grounding_flags' => 'array',
            'rated_at' => 'datetime',
        ];
    }
}
