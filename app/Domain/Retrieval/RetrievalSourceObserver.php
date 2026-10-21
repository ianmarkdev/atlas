<?php

namespace App\Domain\Retrieval;

use App\Jobs\SyncRetrievalSource;
use Illuminate\Database\Eloquent\Model;

/**
 * Attached to every indexed library model: any save or delete queues a re-index of that item.
 */
class RetrievalSourceObserver
{
    public function saved(Model $source): void
    {
        $this->queue($source);
    }

    public function deleted(Model $source): void
    {
        $this->queue($source);
    }

    private function queue(Model $source): void
    {
        SyncRetrievalSource::dispatch((int) $source->getAttribute('workspace_id'), $source->getMorphClass(), (int) $source->getKey());
    }
}
