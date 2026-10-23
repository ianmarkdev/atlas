<?php

namespace App\Domain\Ai;

use Illuminate\Support\Collection;

/**
 * Compares a comparison batch with a baseline batch (M4): per provider, over the samples both
 * batches ran with the same inputs, the server's checks and the average human scores. Anything
 * worse than the baseline is a regression to look at before accepting a prompt or model change.
 * A sample set this small detects regressions; it does not prove a change is better in general.
 *
 * @phpstan-type Metrics array<string, int|float|null>
 * @phpstan-type Report array{providers: array<string, array{metrics: Metrics, baseline: Metrics|null}>, regressions: list<string>, not_compared: list<string>}
 */
class ComparisonBaseline
{
    /** A human score this much lower on average than the baseline counts as a regression. */
    public const SCORE_DROP = 0.5;

    /** Server checks where a higher count is worse. */
    private const COUNTED = ['over_limit', 'banned', 'missing', 'corrected_claims'];

    /**
     * @return Report
     */
    public function compare(string $batch, ?string $baseline = null): array
    {
        $results = LlmComparisonResult::query()->where('batch', $batch)->get();
        $base = $baseline === null ? collect() : LlmComparisonResult::query()->where('batch', $baseline)->get();
        $notCompared = [];

        if ($baseline !== null) {
            // Only samples both batches ran with the same inputs are compared.
            $hashes = $results->pluck('input_hash', 'sample_key');
            $baseHashes = $base->pluck('input_hash', 'sample_key');
            $comparable = $hashes->filter(fn (?string $hash, string $key): bool => $hash !== null && $baseHashes->get($key) === $hash)->keys()->all();
            $notCompared = array_values($hashes->keys()->merge($baseHashes->keys())->unique()->diff($comparable)->sort()->map(fn (mixed $key): string => (string) $key)->all());
            $results = $results->whereIn('sample_key', $comparable);
            $base = $base->whereIn('sample_key', $comparable);
        }

        $providers = [];
        $regressions = [];

        foreach ($results->groupBy('provider') as $provider => $group) {
            $metrics = self::metrics($group);
            $baseGroup = $base->where('provider', $provider);
            $baseMetrics = $baseGroup->isEmpty() ? null : self::metrics($baseGroup);
            $providers[(string) $provider] = ['metrics' => $metrics, 'baseline' => $baseMetrics];

            if ($baseMetrics !== null) {
                array_push($regressions, ...self::regressions((string) $provider, $metrics, $baseMetrics));
            }
        }

        return ['providers' => $providers, 'regressions' => $regressions, 'not_compared' => $notCompared];
    }

    /**
     * @param  Collection<int, LlmComparisonResult>  $results
     * @return Metrics
     */
    private static function metrics(Collection $results): array
    {
        $checks = $results->pluck('checks');
        $metrics = [
            'results' => $results->count(),
            'succeeded_rate' => round($results->where('status', 'succeeded')->count() / max(1, $results->count()), 2),
            'over_limit' => $checks->filter(fn (array $check): bool => (bool) ($check['over_limit'] ?? false))->count(),
            'banned' => $checks->filter(fn (array $check): bool => ($check['banned_found'] ?? []) !== [])->count(),
            'missing' => $checks->filter(fn (array $check): bool => ($check['missing'] ?? []) !== [])->count(),
            'corrected_claims' => (int) $checks->sum(fn (array $check): int => (int) ($check['corrected_claims'] ?? 0)),
            'rated' => $results->filter(fn (LlmComparisonResult $result): bool => $result->isRated())->count(),
        ];

        foreach (LlmComparisonResult::SCORES as $score) {
            $average = $results->whereNotNull("score_{$score}")->avg("score_{$score}");
            $metrics[$score] = $average === null ? null : round((float) $average, 2);
        }

        return $metrics;
    }

    /**
     * @param  Metrics  $metrics
     * @param  Metrics  $baseline
     * @return list<string>
     */
    private static function regressions(string $provider, array $metrics, array $baseline): array
    {
        $found = [];

        if ($metrics['succeeded_rate'] < $baseline['succeeded_rate']) {
            $found[] = "{$provider}: fewer usable outputs ({$metrics['succeeded_rate']} vs {$baseline['succeeded_rate']})";
        }

        foreach (self::COUNTED as $check) {
            if ($metrics[$check] > $baseline[$check]) {
                $found[] = "{$provider}: {$check} {$metrics[$check]} vs {$baseline[$check]}";
            }
        }

        foreach (LlmComparisonResult::SCORES as $score) {
            if ($metrics[$score] !== null && $baseline[$score] !== null && $baseline[$score] - $metrics[$score] >= self::SCORE_DROP) {
                $found[] = "{$provider}: {$score} score {$metrics[$score]} vs {$baseline[$score]}";
            }
        }

        return $found;
    }
}
