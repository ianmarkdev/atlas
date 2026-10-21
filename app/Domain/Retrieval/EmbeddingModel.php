<?php

namespace App\Domain\Retrieval;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * An embedding model the index uses (global, not per workspace). New vectors are written for
 * the model active for write; searches use the one active for read.
 *
 * @property int $id
 * @property string $provider
 * @property string $model
 * @property int $dimensions
 * @property string $distance
 * @property bool $is_active_for_write
 * @property bool $is_active_for_read
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class EmbeddingModel extends Model
{
    /**
     * Managed by EmbeddingModelRegistry.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dimensions' => 'integer',
            'is_active_for_write' => 'boolean',
            'is_active_for_read' => 'boolean',
        ];
    }
}
