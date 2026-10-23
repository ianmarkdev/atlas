<?php

namespace App\Console\Commands;

use App\Domain\Ai\ComparisonBaseline;
use App\Domain\Ai\LlmComparison;
use App\Domain\Ai\LlmComparisonResult;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\Workspace;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * @phpstan-import-type Sample from LlmComparison
 * @phpstan-import-type Report from ComparisonBaseline
 */
#[Signature('app:compare-llm
    {--providers=ollama,claude : Comma-separated providers to compare}
    {--sample=* : Only these sample keys (default: all)}
    {--prompt-version=* : Run a prompt at another version than the samples name, as key:version (e.g. answer_writing:5)}
    {--baseline= : Compare the batch with this earlier batch; exits with failure on a regression}
    {--report= : Do not run anything: report on this batch (with --baseline, against it)}
    {--workspace= : Workspace ULID to record the runs in (default: the only workspace)}
    {--fixture= : Sample file (default tests/Eval/fixtures/llm-comparison.php)}')]
#[Description('Run the synthetic comparison samples through each provider and save outputs, latency and validation for blind scoring, or compare a batch with a baseline')]
class CompareLlm extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(LlmComparison $comparison, ComparisonBaseline $baseline, TenantContext $tenant): int
    {
        $workspace = $this->workspace();

        if ($workspace === null) {
            return self::FAILURE;
        }

        $baselineBatch = self::batchOption($this->option('baseline'));
        $batch = self::batchOption($this->option('report')) ?? $this->runBatch($comparison, $tenant, $workspace);

        if ($batch === null) {
            return self::FAILURE;
        }

        $report = $tenant->run($workspace, fn (): array => $baseline->compare($batch, $baselineBatch));

        return $this->printReport($report, $baselineBatch);
    }

    /**
     * @return string|null the batch id, or null when there was nothing to run
     */
    private function runBatch(LlmComparison $comparison, TenantContext $tenant, Workspace $workspace): ?string
    {
        /** @var array<string, Sample> $samples */
        $samples = require $this->option('fixture') ?: base_path('tests/Eval/fixtures/llm-comparison.php');
        $only = (array) $this->option('sample');

        if ($only !== []) {
            $samples = array_intersect_key($samples, array_flip($only));
        }

        $option = $this->option('providers');
        $providers = array_values(array_filter(array_map('trim', explode(',', is_string($option) ? $option : ''))));
        $versions = [];

        foreach ((array) $this->option('prompt-version') as $pair) {
            [$prompt, $version] = array_pad(explode(':', (string) $pair, 2), 2, '');

            if ($prompt === '' || ! ctype_digit($version)) {
                $this->components->error("Not a prompt version: {$pair} (use key:version, e.g. answer_writing:5).");

                return null;
            }

            $versions[$prompt] = (int) $version;
        }

        if ($samples === [] || $providers === []) {
            $this->components->error('Nothing to run: no matching samples or providers.');

            return null;
        }

        $this->components->info('Comparing '.implode(' vs ', $providers).' on '.count($samples).' synthetic samples (no real applications are sent).');

        $batch = $tenant->run($workspace, fn (): string => $comparison->run($samples, $providers, progress: function (string $sample, string $provider, LlmComparisonResult $result): void {
            $detail = match ($result->status) {
                'succeeded' => "{$result->model} · v{$result->prompt_version} · {$result->latency_ms} ms",
                default => $result->status.': '.mb_strimwidth((string) $result->error_message, 0, 120, '…'),
            };
            $this->components->twoColumnDetail("{$sample} · {$provider}", $detail);
        }, promptVersions: $versions));

        $this->components->info("Saved batch {$batch}. Score it blind under Settings → Model comparison.");

        return $batch;
    }

    /**
     * @param  Report  $report
     */
    private function printReport(array $report, ?string $baselineBatch): int
    {
        $headings = ['results', 'succeeded_rate', 'over_limit', 'banned', 'missing', 'corrected_claims', 'rated', ...LlmComparisonResult::SCORES];
        $rows = [];

        foreach ($report['providers'] as $provider => $row) {
            $rows[] = [$provider, ...array_map(fn (string $metric): string => self::cell($row['metrics'][$metric] ?? null), $headings)];

            if ($row['baseline'] !== null) {
                $rows[] = ['  baseline', ...array_map(fn (string $metric): string => self::cell($row['baseline'][$metric] ?? null), $headings)];
            }
        }

        $this->table(['provider', ...$headings], $rows);

        if ($report['not_compared'] !== []) {
            $this->components->warn('Not compared (inputs changed or missing from one batch): '.implode(', ', $report['not_compared']));
        }

        if ($baselineBatch === null) {
            return self::SUCCESS;
        }

        if ($report['regressions'] === []) {
            $this->components->info("No regressions against {$baselineBatch}.");

            return self::SUCCESS;
        }

        $this->components->error('Regressions against '.$baselineBatch.':');

        foreach ($report['regressions'] as $regression) {
            $this->line("  - {$regression}");
        }

        return self::FAILURE;
    }

    private static function batchOption(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function cell(int|float|null $value): string
    {
        return $value === null ? '—' : (string) $value;
    }

    private function workspace(): ?Workspace
    {
        if ($this->option('workspace')) {
            $workspace = Workspace::query()->where('ulid', $this->option('workspace'))->first();

            if ($workspace === null) {
                $this->components->error('No workspace with that ULID.');
            }

            return $workspace;
        }

        $workspaces = Workspace::query()->limit(2)->get();

        if ($workspaces->count() !== 1) {
            $this->components->error($workspaces->isEmpty() ? 'No workspace yet: run php artisan app:install first.' : 'Several workspaces exist: pass --workspace=<ulid>.');

            return null;
        }

        return $workspaces->first();
    }
}
