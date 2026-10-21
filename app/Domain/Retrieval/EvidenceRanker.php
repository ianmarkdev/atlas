<?php

namespace App\Domain\Retrieval;

use Carbon\CarbonImmutable;

/**
 * App-side reranking of fused hits (docs/04 §4.3): fused rank, status (verified first),
 * specificity and freshness, weighted by config. Then de-duplication: a hit almost identical to
 * one already chosen is dropped, and a job or project summary gives way to its own bullet points
 * or projects in the results.
 */
class EvidenceRanker
{
    /**
     * @param  list<SearchHit>  $hits
     * @return list<SearchHit>
     */
    public function rank(array $hits, int $limit): array
    {
        if ($hits === []) {
            return [];
        }

        $maxRrf = max(array_map(fn (SearchHit $hit): float => $hit->rrf, $hits)) ?: 1.0;
        $weights = (array) config('retrieval.weights');
        $boosts = (array) config('retrieval.status_boost');

        $scored = array_map(fn (SearchHit $hit): SearchHit => $hit->withScore(
            ($hit->rrf / $maxRrf) * (float) $weights['rrf']
            + (float) ($boosts[$hit->unit->status] ?? 0.0) * (float) $weights['status']
            + $hit->unit->specificity_score * (float) $weights['specificity']
            + self::freshness($hit->unit->date_to) * (float) $weights['freshness'],
        ), $hits);

        usort($scored, fn (SearchHit $a, SearchHit $b): int => $b->score <=> $a->score ?: $a->unit->id <=> $b->unit->id);

        return $this->deduplicate($scored, $limit);
    }

    /**
     * 1.0 for current items, halving every `freshness_half_life_years` after they ended.
     */
    public static function freshness(?CarbonImmutable $dateTo, ?CarbonImmutable $today = null): float
    {
        if ($dateTo === null) {
            return 1.0;
        }

        $years = max(0.0, $dateTo->diffInDays($today ?? CarbonImmutable::today()) / 365.25);

        return 0.5 ** ($years / max(0.1, (float) config('retrieval.freshness_half_life_years')));
    }

    /**
     * @param  list<SearchHit>  $ranked
     * @return list<SearchHit>
     */
    private function deduplicate(array $ranked, int $limit): array
    {
        $threshold = (float) config('retrieval.duplicate_similarity');
        $parentsWithChildren = [];

        foreach ($ranked as $hit) {
            if ($hit->unit->parent_type !== null) {
                $parentsWithChildren["{$hit->unit->parent_type}:{$hit->unit->parent_id}"] = true;
            }
        }

        $selected = [];

        foreach ($ranked as $hit) {
            $isSummary = in_array($hit->unit->unit_type, ['employment_summary', 'project_summary'], true);

            if ($isSummary && isset($parentsWithChildren["{$hit->unit->source_type}:{$hit->unit->source_id}"])) {
                continue;
            }

            foreach ($selected as $chosen) {
                if ($hit->vector !== null && $chosen->vector !== null && self::cosine($hit->vector, $chosen->vector) > $threshold) {
                    continue 2;
                }
            }

            $selected[] = $hit;

            if (count($selected) >= $limit) {
                break;
            }
        }

        return $selected;
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private static function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        foreach ($a as $i => $value) {
            $dot += $value * ($b[$i] ?? 0.0);
            $normA += $value * $value;
            $normB += ($b[$i] ?? 0.0) ** 2;
        }

        return $normA > 0 && $normB > 0 ? $dot / (sqrt($normA) * sqrt($normB)) : 0.0;
    }
}
