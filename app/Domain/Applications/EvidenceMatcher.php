<?php

namespace App\Domain\Applications;

use App\Domain\Retrieval\HybridSearch;
use App\Domain\Retrieval\RetrievalQuery;
use App\Domain\Retrieval\SearchHit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * The requirement ↔ evidence matrix (M7): for each must-have and nice-to-have of a brief, the
 * best library facts from hybrid search, and a coverage rating:
 *
 * - `strong`: at least one verified bullet point or approved library answer matches;
 * - `partial`: only proposed facts, or only job/project summaries, match;
 * - `gap`: nothing matches — the candidate should add evidence or address it in the answer.
 */
class EvidenceMatcher
{
    public const PER_REQUIREMENT = 5;

    public function __construct(private HybridSearch $search) {}

    /**
     * @param  array<string, mixed>  $brief
     * @return array{semantic: bool, semantic_reason: ?string, requirements: list<array<string, mixed>>}
     */
    public function match(array $brief, int $candidateProfileId): array
    {
        $requirements = [];
        $semantic = true;
        $reason = null;

        foreach (['must_haves' => 'must', 'nice_to_haves' => 'nice'] as $list => $kind) {
            foreach ((array) ($brief[$list] ?? []) as $index => $item) {
                $text = (string) ($item['requirement'] ?? '');

                if (trim($text) === '') {
                    continue;
                }

                $result = $this->search->search(new RetrievalQuery($text, $candidateProfileId, self::PER_REQUIREMENT));
                $semantic = $semantic && $result->semantic;
                $reason ??= $result->semanticUnavailableReason;

                $requirements[] = [
                    'kind' => $kind,
                    'index' => $index,
                    'requirement' => $text,
                    'coverage' => self::coverage($result->hits),
                    'evidence' => $this->evidence($result->hits),
                ];
            }
        }

        return ['semantic' => $semantic, 'semantic_reason' => $reason, 'requirements' => $requirements];
    }

    /**
     * @param  list<SearchHit>  $hits
     */
    public static function coverage(array $hits): string
    {
        if ($hits === []) {
            return 'gap';
        }

        foreach ($hits as $hit) {
            if (in_array($hit->unit->unit_type, ['claim', 'library_answer'], true) && in_array($hit->unit->status, ['verified', 'approved'], true)) {
                return 'strong';
            }
        }

        return 'partial';
    }

    /**
     * @param  list<SearchHit>  $hits
     * @return list<array{ref: ?string, unit_type: string, status: string, text: string, score: float}>
     */
    private function evidence(array $hits): array
    {
        $ulids = $this->ulidsOf($hits);

        return array_map(fn (SearchHit $hit): array => [
            'ref' => isset($ulids[$hit->unit->source_type][$hit->unit->source_id])
                ? "{$hit->unit->source_type}:{$ulids[$hit->unit->source_type][$hit->unit->source_id]}"
                : null,
            'unit_type' => $hit->unit->unit_type,
            'status' => $hit->unit->status,
            'text' => $hit->unit->search_text,
            'score' => round($hit->score, 3),
        ], $hits);
    }

    /**
     * Source ULIDs by type and id, so the brief can link to the library items.
     *
     * @param  list<SearchHit>  $hits
     * @return array<string, array<int, string>>
     */
    private function ulidsOf(array $hits): array
    {
        $ids = [];

        foreach ($hits as $hit) {
            $ids[$hit->unit->source_type][] = $hit->unit->source_id;
        }

        $ulids = [];

        foreach ($ids as $type => $sourceIds) {
            /** @var class-string<Model>|null $class */
            $class = Relation::getMorphedModel($type);

            if ($class !== null) {
                $ulids[$type] = $class::query()->whereIn('id', $sourceIds)->pluck('ulid', 'id')->all();
            }
        }

        return $ulids;
    }
}
