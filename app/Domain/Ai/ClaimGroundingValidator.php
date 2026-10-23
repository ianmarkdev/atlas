<?php

namespace App\Domain\Ai;

/**
 * Server-side claim labelling (docs/04 §4.5). The model proposes a label for each claim in its
 * answer; this decides what the label may be, from the evidence actually supplied:
 *
 * - `verified` needs at least one cited evidence item that is verified or approved;
 * - `inferred` needs at least one cited evidence item of any status;
 * - numbers (and percentages, amounts) must appear in the cited evidence, otherwise the claim
 *   becomes `proposed` (to confirm) — the model may not invent metrics;
 * - evidence ids that were not supplied are dropped.
 *
 * Claims that fail are downgraded (never upgraded) and flagged.
 */
class ClaimGroundingValidator
{
    public const LABELS = ['verified', 'inferred', 'proposed', 'hypothetical'];

    /**
     * The model's claims in the shape `check` takes, each statement once (small models repeat
     * claims).
     *
     * @param  array<int, mixed>  $claims
     * @return list<array{text: string, classification: string, evidence_ids: list<string>}>
     */
    public static function distinct(array $claims): array
    {
        $unique = [];

        foreach ($claims as $claim) {
            $key = mb_strtolower(trim((string) ($claim['text'] ?? '')));

            if ($key === '' || isset($unique[$key])) {
                continue;
            }

            $unique[$key] = [
                'text' => (string) ($claim['text'] ?? ''),
                'classification' => (string) ($claim['classification'] ?? ''),
                'evidence_ids' => array_values(array_map(strval(...), (array) ($claim['evidence_ids'] ?? []))),
            ];
        }

        return array_values($unique);
    }

    /**
     * @param  list<array{text: string, classification: string, evidence_ids: list<string>}>  $claims
     * @param  array<string, array{status: string, text: string}>  $evidence  by evidence id ("E1")
     * @return list<array{text: string, classification: string, model_classification: string, evidence_ids: list<string>, flags: list<string>}>
     */
    public function check(array $claims, array $evidence): array
    {
        return array_map(function (array $claim) use ($evidence): array {
            $flags = [];
            $label = in_array($claim['classification'], self::LABELS, true) ? $claim['classification'] : 'hypothetical';

            if ($label !== $claim['classification']) {
                $flags[] = 'unknown_label';
            }

            $cited = array_values(array_filter($claim['evidence_ids'], fn (string $id): bool => isset($evidence[$id])));

            if (count($cited) !== count($claim['evidence_ids'])) {
                $flags[] = 'unknown_evidence';
            }

            $citedEvidence = array_map(fn (string $id): array => $evidence[$id], $cited);
            $hasVerified = array_filter($citedEvidence, fn (array $item): bool => in_array($item['status'], ['verified', 'approved'], true)) !== [];

            if ($label === 'verified' && ! $hasVerified) {
                $label = $cited === [] ? 'hypothetical' : 'inferred';
                $flags[] = 'downgraded_no_verified_evidence';
            }

            if ($label === 'inferred' && $cited === []) {
                $label = 'hypothetical';
                $flags[] = 'downgraded_no_evidence';
            }

            if (in_array($label, ['verified', 'inferred'], true) && $this->hasUnsupportedNumbers($claim['text'], $citedEvidence)) {
                $label = 'proposed';
                $flags[] = 'metric_without_source';
            }

            return [
                'text' => $claim['text'],
                'classification' => $label,
                'model_classification' => $claim['classification'],
                'evidence_ids' => $cited,
                'flags' => $flags,
            ];
        }, $claims);
    }

    /**
     * @param  list<array{status: string, text: string}>  $citedEvidence
     */
    private function hasUnsupportedNumbers(string $text, array $citedEvidence): bool
    {
        $sources = implode("\n", array_column($citedEvidence, 'text'));
        $normalise = fn (string $number): string => str_replace([',', ' '], '', $number);
        $available = array_map($normalise, self::numbers($sources));

        foreach (self::numbers($text) as $number) {
            if (! in_array($normalise($number), $available, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function numbers(string $text): array
    {
        preg_match_all('/\d[\d,]*(?:\.\d+)?/u', $text, $matches);

        return array_values(array_unique($matches[0]));
    }
}
