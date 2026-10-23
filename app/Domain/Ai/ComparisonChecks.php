<?php

namespace App\Domain\Ai;

use App\Domain\Shared\PlainText;

/**
 * Checks of a comparison output the server can make without a person (M4 baseline): the length
 * against the sample's limit, phrases a writing rule bans, things the answer must mention, and
 * the claim labels the server had to correct. Human scores cover what these cannot judge.
 *
 * @phpstan-type Checks array{word_count?: int, word_limit?: int|null, over_limit?: bool, placeholders?: int, banned_found?: list<string>, missing?: list<string>, corrected_claims: int}
 */
class ComparisonChecks
{
    /**
     * @param  array{variables: array<string, string>, checks?: array{banned?: list<string>, mentions?: list<string>}}  $sample
     * @param  array<string, mixed>|null  $output
     * @param  list<array<string, mixed>>  $grounding  the server-checked claims (LlmComparison)
     * @return Checks
     */
    public static function of(array $sample, ?array $output, array $grounding): array
    {
        $checks = ['corrected_claims' => count(array_filter($grounding, fn (array $claim): bool => ($claim['flags'] ?? []) !== []))];
        $answer = $output['answer'] ?? null;

        if (! is_string($answer)) {
            return $checks;
        }

        $words = PlainText::wordCount($answer);
        $limit = preg_match('/at most (\d+) words/i', $sample['variables']['limit'] ?? '', $match) === 1 ? (int) $match[1] : null;
        $lower = mb_strtolower($answer);

        return [
            'word_count' => $words,
            'word_limit' => $limit,
            'over_limit' => $limit !== null && $words > $limit,
            'placeholders' => preg_match_all('/\[confirm:[^\]]*\]/i', $answer) ?: 0,
            'banned_found' => array_values(array_filter($sample['checks']['banned'] ?? [], fn (string $phrase): bool => str_contains($lower, mb_strtolower($phrase)))),
            'missing' => array_values(array_filter($sample['checks']['mentions'] ?? [], fn (string $phrase): bool => ! str_contains($lower, mb_strtolower($phrase)))),
            ...$checks,
        ];
    }
}
