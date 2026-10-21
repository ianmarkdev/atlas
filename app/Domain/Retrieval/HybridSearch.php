<?php

namespace App\Domain\Retrieval;

use App\Domain\Retrieval\Embeddings\EmbeddingFailed;
use App\Domain\Retrieval\Embeddings\EmbeddingProvider;
use App\Domain\Retrieval\Embeddings\Vector;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Hybrid retrieval in one SQL round trip (docs/04 §4.3, S3): semantic top-N by cosine distance
 * and keyword top-N by `ts_rank_cd` (OR semantics), fused with Reciprocal Rank Fusion, then
 * reranked and de-duplicated by EvidenceRanker. Exact scan, no ANN index (S3 decision 1).
 */
class HybridSearch
{
    public function __construct(
        private TenantContext $tenant,
        private EmbeddingProvider $provider,
        private EmbeddingModelRegistry $models,
        private EvidenceRanker $ranker,
    ) {}

    public function search(RetrievalQuery $query): SearchResult
    {
        $workspaceId = $this->tenant->workspaceId();
        $keywords = self::orQuery($query->text);
        [$vector, $model, $reason] = $this->queryVector($query->text);

        if ($keywords === '' && $vector === null) {
            return new SearchResult([], false, $reason);
        }

        $n = (int) config('retrieval.candidates_per_signal');
        $k = (int) config('retrieval.rrf_k');
        $typePlaceholders = implode(',', array_fill(0, count($query->unitTypes), '?'));
        $statusPlaceholders = implode(',', array_fill(0, count($query->statuses), '?'));
        $filterBindings = [$workspaceId, $query->candidateProfileId, ...$query->unitTypes, ...$query->statuses];

        $semanticSql = $vector === null
            ? 'SELECT NULL::bigint AS id, NULL::double precision AS distance, NULL::bigint AS rank, NULL::text AS embedding WHERE false'
            : "SELECT e.retrieval_unit_id AS id, e.embedding <=> ?::halfvec AS distance,
                      row_number() OVER (ORDER BY e.embedding <=> ?::halfvec) AS rank, e.embedding::text AS embedding
                 FROM embeddings e JOIN filtered f ON f.id = e.retrieval_unit_id
                WHERE e.embedding_model_id = ? AND e.workspace_id = ?
                  AND e.embedding <=> ?::halfvec <= ?
                ORDER BY e.embedding <=> ?::halfvec
                LIMIT {$n}";
        $maxDistance = 1 - (float) config('retrieval.min_similarity');
        $semanticBindings = $vector === null ? [] : [$vector, $vector, $model?->id, $workspaceId, $vector, $maxDistance, $vector];

        $lexicalSql = $keywords === ''
            ? 'SELECT NULL::bigint AS id, NULL::bigint AS rank WHERE false'
            : "SELECT u.id, row_number() OVER (ORDER BY ts_rank_cd(u.tsv, q) DESC, u.id) AS rank
                 FROM retrieval_units u JOIN filtered f ON f.id = u.id, websearch_to_tsquery('english', ?) q
                WHERE u.tsv @@ q
                ORDER BY ts_rank_cd(u.tsv, q) DESC, u.id
                LIMIT {$n}";
        $lexicalBindings = $keywords === '' ? [] : [$keywords];

        $rows = DB::select(
            "WITH filtered AS (
                SELECT id FROM retrieval_units
                 WHERE workspace_id = ? AND candidate_profile_id = ? AND is_active
                   AND unit_type IN ({$typePlaceholders}) AND status IN ({$statusPlaceholders})
             ),
             semantic AS ({$semanticSql}),
             lexical AS ({$lexicalSql})
             SELECT COALESCE(s.id, l.id) AS id, s.rank AS semantic_rank, 1 - s.distance AS similarity, l.rank AS lexical_rank,
                    COALESCE(1.0 / ({$k} + s.rank), 0) + COALESCE(1.0 / ({$k} + l.rank), 0) AS rrf, s.embedding
               FROM semantic s FULL OUTER JOIN lexical l ON l.id = s.id
              ORDER BY rrf DESC, id
              LIMIT ".(int) config('retrieval.fused_limit'),
            [...$filterBindings, ...$semanticBindings, ...$lexicalBindings],
        );

        $units = RetrievalUnit::query()->whereIn('id', array_column($rows, 'id'))->get()->keyBy('id');
        $hits = [];

        foreach ($rows as $row) {
            $unit = $units->get($row->id);

            // The SQL filters already scope to the workspace and profile; this is the belt to those braces.
            if ($unit === null || $unit->workspace_id !== $workspaceId || $unit->candidate_profile_id !== $query->candidateProfileId) {
                throw new LogicException('Retrieval returned a unit outside the requested workspace or profile.');
            }

            $hits[] = new SearchHit(
                $unit,
                (float) $row->rrf,
                $row->semantic_rank === null ? null : (int) $row->semantic_rank,
                $row->similarity === null ? null : (float) $row->similarity,
                $row->lexical_rank === null ? null : (int) $row->lexical_rank,
                vector: is_string($row->embedding) ? self::parseVector($row->embedding) : null,
            );
        }

        return new SearchResult($this->ranker->rank($hits, $query->limit), $vector !== null, $reason);
    }

    /**
     * Keywords joined with OR (S3: AND semantics match almost nothing for multi-term queries).
     */
    public static function orQuery(string $text): string
    {
        preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}+#.]*/u', mb_strtolower($text), $matches);
        $words = array_slice(array_values(array_unique(array_map(fn (string $word): string => rtrim($word, '.'), $matches[0]))), 0, 40);

        return implode(' or ', array_filter($words, fn (string $word): bool => mb_strlen($word) > 1 && $word !== 'or'));
    }

    /**
     * @return array{?string, ?EmbeddingModel, ?string} vector literal, model, and why semantic search is off
     */
    private function queryVector(string $text): array
    {
        $model = $this->models->forRead();

        if ($model === null) {
            return [null, null, __('Nothing has been embedded yet; showing keyword matches only.')];
        }

        if ($model->provider !== $this->provider->spec()['provider'] || $model->model !== $this->provider->spec()['model']) {
            return [null, $model, __('The configured embedding model differs from the indexed one; run php artisan app:rebuild-retrieval-index --embed --promote.')];
        }

        try {
            return [Vector::literal($this->provider->embedQuery($text)), $model, null];
        } catch (EmbeddingFailed $exception) {
            report($exception);

            return [null, $model, $exception->getMessage()];
        }
    }

    /**
     * @return list<float>
     */
    private static function parseVector(string $literal): array
    {
        return array_map(floatval(...), explode(',', trim($literal, '[]')));
    }
}
