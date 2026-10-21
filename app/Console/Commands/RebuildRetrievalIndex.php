<?php

namespace App\Console\Commands;

use App\Domain\Retrieval\EmbeddingModelRegistry;
use App\Domain\Retrieval\RetrievalIndexer;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\Workspace;
use App\Jobs\EmbedRetrievalUnits;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:rebuild-retrieval-index
    {--embed : Also embed every unit that has no current vector (needs the embedding provider)}
    {--promote : After embedding, make the configured model the one searches read (after a model switch)}')]
#[Description('Rebuild the search index from the library of every workspace, optionally embedding it')]
class RebuildRetrievalIndex extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(RetrievalIndexer $indexer, TenantContext $tenant, EmbeddingModelRegistry $models): int
    {
        foreach (Workspace::query()->orderBy('id')->get() as $workspace) {
            $count = $tenant->run($workspace, fn (): int => $indexer->rebuild());
            $this->components->info("{$workspace->name}: indexed {$count} library items.");

            if ($this->option('embed')) {
                $this->components->task('Embedding', fn () => EmbedRetrievalUnits::dispatchSync($workspace->id));
            }
        }

        if ($this->option('promote')) {
            $model = $models->promoteWriteModelToRead();
            $this->components->info("Searches now read {$model->provider}/{$model->model}.");
        }

        return self::SUCCESS;
    }
}
