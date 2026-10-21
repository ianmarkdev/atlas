<?php

namespace App\Domain\Retrieval;

use App\Domain\Tenancy\BelongsToWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The vector of one retrieval unit under one embedding model. `content_hash` is the unit's
 * hash when it was embedded: a mismatch means the vector is stale. The `embedding` column is
 * written and searched with SQL only (pgvector `halfvec`).
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $retrieval_unit_id
 * @property int $embedding_model_id
 * @property string $content_hash
 * @property CarbonImmutable $created_at
 */
class Embedding extends Model
{
    use BelongsToWorkspace;

    public const UPDATED_AT = null;

    /**
     * Written only through EmbedRetrievalUnits.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * @var list<string>
     */
    protected $hidden = ['embedding'];

    /**
     * @return BelongsTo<RetrievalUnit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(RetrievalUnit::class, 'retrieval_unit_id');
    }

    /**
     * @return BelongsTo<EmbeddingModel, $this>
     */
    public function embeddingModel(): BelongsTo
    {
        return $this->belongsTo(EmbeddingModel::class);
    }
}
