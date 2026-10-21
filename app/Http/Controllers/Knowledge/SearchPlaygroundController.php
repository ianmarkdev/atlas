<?php

namespace App\Http\Controllers\Knowledge;

use App\Domain\Knowledge\CandidateProfile;
use App\Domain\Retrieval\Embedding;
use App\Domain\Retrieval\EmbeddingModelRegistry;
use App\Domain\Retrieval\Embeddings\EmbeddingProvider;
use App\Domain\Retrieval\HybridSearch;
use App\Domain\Retrieval\RetrievalIndexer;
use App\Domain\Retrieval\RetrievalQuery;
use App\Domain\Retrieval\RetrievalUnit;
use App\Domain\Retrieval\SearchHit;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Jobs\EmbedRetrievalUnits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Candidate-only debug page for retrieval (M6): run a query against the library and see how
 * each hit was found and ranked, plus the state of the index.
 */
class SearchPlaygroundController extends Controller
{
    public function index(Request $request, HybridSearch $search, EmbeddingModelRegistry $models, EmbeddingProvider $provider): Response
    {
        $text = trim($request->string('q')->toString());
        $result = $text === '' ? null : $search->search(new RetrievalQuery($text, CandidateProfile::current()->id, limit: 12));
        $readModel = $models->forRead();

        return Inertia::render('knowledge/search', [
            'q' => $text,
            'result' => $result === null ? null : [
                'semantic' => $result->semantic,
                'reason' => $result->semanticUnavailableReason,
                'hits' => array_map(fn (SearchHit $hit): array => [
                    'id' => $hit->unit->id,
                    'unit_type' => $hit->unit->unit_type,
                    'status' => $hit->unit->status,
                    'text' => $hit->unit->search_text,
                    'score' => round($hit->score, 3),
                    'rrf' => round($hit->rrf, 4),
                    'semantic_rank' => $hit->semanticRank,
                    'similarity' => $hit->similarity === null ? null : round($hit->similarity, 3),
                    'lexical_rank' => $hit->lexicalRank,
                ], $result->hits),
            ],
            'index' => [
                'units' => RetrievalUnit::query()->count(),
                'active' => RetrievalUnit::query()->where('is_active', true)->count(),
                'embedded' => $readModel === null ? 0 : Embedding::query()
                    ->where('embedding_model_id', $readModel->id)
                    ->whereHas('unit', fn ($query) => $query->where('is_active', true)->whereColumn('retrieval_units.content_hash', 'embeddings.content_hash'))
                    ->count(),
                'read_model' => $readModel === null ? null : "{$readModel->provider}/{$readModel->model} ({$readModel->dimensions})",
                'configured' => implode('/', array_slice($provider->spec(), 0, 2)),
            ],
        ]);
    }

    /**
     * Rebuild this workspace's index now and queue the embedding of what changed.
     */
    public function rebuild(Request $request, RetrievalIndexer $indexer, TenantContext $tenant): RedirectResponse
    {
        $count = $indexer->rebuild();
        EmbedRetrievalUnits::dispatch($tenant->workspaceId());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Re-indexed :count library items; embedding runs in the background.', ['count' => $count])]);

        return back();
    }
}
