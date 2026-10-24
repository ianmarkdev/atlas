<?php

namespace App\Jobs;

use App\Domain\Ai\AiQueue;
use App\Domain\Retrieval\EmbeddingModelRegistry;
use App\Domain\Retrieval\Embeddings\EmbeddingProvider;
use App\Domain\Retrieval\Embeddings\Vector;
use App\Domain\Retrieval\RetrievalUnit;
use App\Jobs\Middleware\WithWorkspaceContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Embed the workspace's active units that have no current vector under the write model. A unit
 * whose text did not change (same content hash) is skipped, so re-running costs nothing.
 */
class EmbedRetrievalUnits implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * Seconds between attempts (e.g. Ollama still starting).
     *
     * @var list<int>
     */
    public array $backoff = [10, 60];

    public function __construct(public int $workspaceId)
    {
        $this->afterCommit();
        $this->onQueue(AiQueue::embeddings());
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new WithWorkspaceContext($this->workspaceId)];
    }

    public function handle(EmbeddingProvider $provider, EmbeddingModelRegistry $models): void
    {
        $model = $models->forWrite();
        $batchSize = max(1, (int) config('embeddings.batch_size'));

        RetrievalUnit::query()
            ->where('is_active', true)
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')
                ->from('embeddings')
                ->whereColumn('embeddings.retrieval_unit_id', 'retrieval_units.id')
                ->whereColumn('embeddings.content_hash', 'retrieval_units.content_hash')
                ->where('embeddings.embedding_model_id', $model->id))
            ->select(['id', 'workspace_id', 'search_text', 'content_hash'])
            ->chunkById($batchSize, function (Collection $units) use ($provider, $model): void {
                $vectors = $provider->embedDocuments(array_values($units->pluck('search_text')->all()));

                DB::transaction(function () use ($units, $vectors, $model): void {
                    foreach (array_values($units->all()) as $i => $unit) {
                        DB::statement(
                            'INSERT INTO embeddings (workspace_id, retrieval_unit_id, embedding_model_id, content_hash, embedding, created_at)
                             VALUES (?, ?, ?, ?, ?::halfvec, now())
                             ON CONFLICT (retrieval_unit_id, embedding_model_id)
                             DO UPDATE SET content_hash = EXCLUDED.content_hash, embedding = EXCLUDED.embedding, created_at = now()',
                            [$unit->workspace_id, $unit->id, $model->id, $unit->content_hash, Vector::literal($vectors[$i])],
                        );
                    }
                });
            });
    }
}
