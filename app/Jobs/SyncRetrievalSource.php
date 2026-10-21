<?php

namespace App\Jobs;

use App\Domain\Retrieval\RetrievalIndexer;
use App\Jobs\Middleware\WithWorkspaceContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Re-index one library item after it changed (dispatched after the transaction commits).
 */
class SyncRetrievalSource implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $workspaceId, public string $sourceType, public int $sourceId)
    {
        $this->afterCommit();
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new WithWorkspaceContext($this->workspaceId)];
    }

    public function handle(RetrievalIndexer $indexer): void
    {
        /** @var class-string<Model>|null $class */
        $class = Relation::getMorphedModel($this->sourceType);
        $source = $class === null ? null : $class::query()->find($this->sourceId);

        if ($source === null) {
            $indexer->forget($this->sourceType, $this->sourceId);

            return;
        }

        $indexer->sync($source);

        EmbedRetrievalUnits::dispatch($this->workspaceId);
    }
}
