<?php

namespace App\Domain\Applications;

use App\Domain\Retrieval\HybridSearch;
use App\Domain\Retrieval\RetrievalQuery;

/**
 * The facts an AI answer may use (M8): the best evidence the latest analysis matched to each
 * must-have (two each) and nice-to-have (one each), plus a search on the question itself —
 * de-duplicated, capped, and numbered E1, E2, … for the prompt and the claim check.
 */
class EvidencePackBuilder
{
    public const MAX_ITEMS = 15;

    public function __construct(private HybridSearch $search) {}

    /**
     * @return array<string, array{ref: ?string, status: string, text: string}>
     */
    public function build(JobApplication $application, ApplicationQuestion $question): array
    {
        $items = [];
        $analysis = $application->analyses()->where('status', 'succeeded')->first();

        foreach ((array) ($analysis->matches['requirements'] ?? []) as $requirement) {
            $take = ($requirement['kind'] ?? null) === 'must' ? 2 : 1;

            foreach (array_slice((array) ($requirement['evidence'] ?? []), 0, $take) as $evidence) {
                $items[] = $evidence;
            }
        }

        $query = $question->isCoverLetter()
            ? trim($application->role_title.'. '.((string) ($analysis->brief['summary'] ?? '')))
            : $question->prompt_text;
        $result = $this->search->search(new RetrievalQuery($query, $application->candidate_profile_id, 6));

        foreach ($result->hits as $hit) {
            $items[] = ['ref' => null, 'status' => $hit->unit->status, 'text' => $hit->unit->search_text, 'unit_type' => $hit->unit->unit_type];
        }

        $pack = [];
        $seen = [];
        $number = 0;

        foreach ($items as $item) {
            $text = trim((string) ($item['text'] ?? ''));
            $status = (string) ($item['status'] ?? '');
            $key = mb_strtolower($text);

            if ($text === '' || isset($seen[$key]) || ! in_array($status, ['verified', 'approved', 'proposed'], true)) {
                continue;
            }

            $seen[$key] = true;
            $number++;
            $pack["E{$number}"] = ['ref' => $item['ref'] ?? null, 'status' => $status, 'text' => $text];

            if ($number >= self::MAX_ITEMS) {
                break;
            }
        }

        return $pack;
    }

    /**
     * The pack as tagged lines for the prompt.
     *
     * @param  array<string, array{ref: ?string, status: string, text: string}>  $pack
     */
    public static function render(array $pack): string
    {
        if ($pack === []) {
            return '(no evidence: the candidate has not added matching facts yet — use placeholders, do not invent)';
        }

        $lines = [];

        foreach ($pack as $id => $item) {
            $lines[] = sprintf('<item id="%s" status="%s">%s</item>', $id, $item['status'], htmlspecialchars($item['text'], ENT_NOQUOTES));
        }

        return implode("\n", $lines);
    }
}
