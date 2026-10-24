<?php

namespace App\Domain\Applications;

use App\Domain\Knowledge\Claim;
use App\Domain\Resumes\ResumeVariant;
use App\Domain\Retrieval\HybridSearch;
use App\Domain\Retrieval\RetrievalQuery;
use App\Domain\Retrieval\SearchHit;

/**
 * Which resume to start a job's tailored resume from. Each requirement of the analysis is
 * searched among the resume's own bullet points and rated like the library match
 * (EvidenceMatcher::coverage): `strong`, `partial` or `gap`. Resumes are ranked by must-haves
 * strongly covered, then partly covered, then the same for nice-to-haves; ties go to the
 * default resume, then by name. No AI call, so the same resumes always rank the same.
 *
 * A requirement the resume does not show strongly is either `library` (a better fact is in the
 * library: add it while tailoring) or `experience` (the library has nothing better either:
 * address it honestly, do not invent it).
 *
 * @phpstan-type Counts array{strong: int, partial: int, gap: int}
 * @phpstan-type RankedResume array{ulid: string, name: string, is_default: bool, revision_id: ?int, must: Counts, nice: Counts, requirements: list<array{kind: string, index: int, requirement: string, coverage: string, library_coverage: string, missing_from: ?string, evidence: list<array{status: string, text: string}>}>}
 * @phpstan-type Ranking array{ranked_at: string, resumes: list<RankedResume>}
 */
class ResumeRanker
{
    /** Bullet points kept per requirement, to show why it is covered. */
    public const EVIDENCE = 2;

    private const STRENGTH = ['gap' => 0, 'partial' => 1, 'strong' => 2];

    public function __construct(private HybridSearch $search) {}

    /**
     * @param  array<string, mixed>  $matches  the analysis's requirement matrix (EvidenceMatcher::match)
     * @return Ranking
     */
    public function rank(array $matches, int $candidateProfileId): array
    {
        $requirements = array_values(array_filter((array) ($matches['requirements'] ?? []), is_array(...)));
        $variants = ResumeVariant::query()
            ->active()
            ->where('candidate_profile_id', $candidateProfileId)
            ->with('currentRevision')
            ->get();
        $documents = $variants->mapWithKeys(fn (ResumeVariant $variant): array => [$variant->id => $variant->document()]);
        $claimIds = Claim::query()
            ->whereIn('ulid', $documents->flatMap(fn (array $document): array => self::claimUlids($document))->unique()->values()->all())
            ->pluck('id', 'ulid');

        $ranked = $variants->map(function (ResumeVariant $variant) use ($documents, $claimIds, $requirements, $candidateProfileId): array {
            $ids = array_values(array_filter(array_map(fn (string $ulid): ?int => $claimIds->get($ulid), self::claimUlids($documents[$variant->id]))));
            $rows = array_map(fn (array $requirement): array => $this->rate($requirement, $ids, $candidateProfileId), $requirements);

            return [
                'ulid' => $variant->ulid,
                'name' => $variant->name,
                'is_default' => $variant->is_default,
                'revision_id' => $variant->current_revision_id,
                'must' => self::counts($rows, 'must'),
                'nice' => self::counts($rows, 'nice'),
                'requirements' => $rows,
            ];
        })->sort(fn (array $a, array $b): int => [$b['must']['strong'], $b['must']['partial'], $b['nice']['strong'], $b['nice']['partial'], $b['is_default'], $a['name'], $a['ulid']]
            <=> [$a['must']['strong'], $a['must']['partial'], $a['nice']['strong'], $a['nice']['partial'], $a['is_default'], $b['name'], $b['ulid']]);

        return ['ranked_at' => now()->toIso8601String(), 'resumes' => array_values($ranked->all())];
    }

    /**
     * @param  array<string, mixed>  $requirement
     * @param  list<int>  $claimIds  the resume's bullet points
     * @return array{kind: string, index: int, requirement: string, coverage: string, library_coverage: string, missing_from: ?string, evidence: list<array{status: string, text: string}>}
     */
    private function rate(array $requirement, array $claimIds, int $candidateProfileId): array
    {
        $text = (string) ($requirement['requirement'] ?? '');
        $hits = $this->search->search(new RetrievalQuery($text, $candidateProfileId, EvidenceMatcher::PER_REQUIREMENT, ['claim'], claimIds: $claimIds))->hits;
        $coverage = EvidenceMatcher::coverage($hits);
        $library = (string) ($requirement['coverage'] ?? 'gap');

        return [
            'kind' => (string) ($requirement['kind'] ?? 'must'),
            'index' => (int) ($requirement['index'] ?? 0),
            'requirement' => $text,
            'coverage' => $coverage,
            'library_coverage' => $library,
            'missing_from' => match (true) {
                $coverage === 'strong' => null,
                (self::STRENGTH[$library] ?? 0) > self::STRENGTH[$coverage] => 'library',
                default => 'experience',
            },
            'evidence' => array_map(fn (SearchHit $hit): array => [
                'status' => $hit->unit->status,
                'text' => $hit->unit->search_text,
            ], array_slice($hits, 0, self::EVIDENCE)),
        ];
    }

    /**
     * @param  list<array{kind: string, coverage: string}>  $rows
     * @return Counts
     */
    private static function counts(array $rows, string $kind): array
    {
        $counts = ['strong' => 0, 'partial' => 0, 'gap' => 0];

        foreach ($rows as $row) {
            if ($row['kind'] !== $kind) {
                continue;
            }

            match ($row['coverage']) {
                'strong' => $counts['strong']++,
                'partial' => $counts['partial']++,
                default => $counts['gap']++,
            };
        }

        return $counts;
    }

    /**
     * The bullet points a resume shows: under its jobs, their projects and its own projects.
     *
     * @param  array<string, mixed>  $document
     * @return list<string>
     */
    private static function claimUlids(array $document): array
    {
        $items = [];

        foreach ((array) ($document['experience'] ?? []) as $job) {
            $items[] = $job;
            array_push($items, ...(array) ($job['projects'] ?? []));
        }

        array_push($items, ...(array) ($document['projects'] ?? []));
        $ulids = [];

        foreach ($items as $item) {
            foreach ((array) ($item['bullets'] ?? []) as $bullet) {
                if (is_string($bullet['claim'] ?? null)) {
                    $ulids[] = $bullet['claim'];
                }
            }
        }

        return $ulids;
    }
}
