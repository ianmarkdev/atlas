<?php

namespace App\Domain\Retrieval;

use App\Domain\Retrieval\Embeddings\EmbeddingProvider;
use Illuminate\Support\Facades\DB;

/**
 * Which embedding model rows the index writes and reads. The configured provider's model is
 * registered on first use; when it differs from the current one, it becomes the write model
 * and reads switch only after a full backfill (`app:rebuild-retrieval-index --embed --promote`).
 */
class EmbeddingModelRegistry
{
    public function __construct(private EmbeddingProvider $provider) {}

    /**
     * The model of the configured provider (new vectors go here).
     */
    public function forWrite(): EmbeddingModel
    {
        $spec = $this->provider->spec();

        return DB::transaction(function () use ($spec): EmbeddingModel {
            $model = EmbeddingModel::query()->where($spec)->lockForUpdate()->first();

            if ($model === null) {
                $model = new EmbeddingModel;
                $model->forceFill([...$spec, 'distance' => 'cosine'])->save();
            }

            if (! $model->is_active_for_write) {
                EmbeddingModel::query()->whereKeyNot($model->id)->update(['is_active_for_write' => false]);
                $model->forceFill([
                    'is_active_for_write' => true,
                    // The very first model is readable at once; later ones after their backfill.
                    'is_active_for_read' => ! EmbeddingModel::query()->whereKeyNot($model->id)->where('is_active_for_read', true)->exists(),
                ])->save();
            }

            return $model;
        });
    }

    /**
     * The model searches use, or null before anything was embedded.
     */
    public function forRead(): ?EmbeddingModel
    {
        return EmbeddingModel::query()->where('is_active_for_read', true)->first();
    }

    /**
     * After a full backfill: read from the write model and stop reading older ones.
     */
    public function promoteWriteModelToRead(): EmbeddingModel
    {
        $model = $this->forWrite();

        DB::transaction(function () use ($model): void {
            EmbeddingModel::query()->whereKeyNot($model->id)->update(['is_active_for_read' => false]);
            $model->forceFill(['is_active_for_read' => true])->save();
        });

        return $model;
    }
}
