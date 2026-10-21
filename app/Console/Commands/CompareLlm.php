<?php

namespace App\Console\Commands;

use App\Domain\Ai\LlmComparison;
use App\Domain\Ai\LlmComparisonResult;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\Workspace;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:compare-llm
    {--providers=ollama,claude : Comma-separated providers to compare}
    {--sample=* : Only these sample keys (default: all)}
    {--workspace= : Workspace ULID to record the runs in (default: the only workspace)}
    {--fixture= : Sample file (default tests/Eval/fixtures/llm-comparison.php)}')]
#[Description('Run the synthetic comparison samples through each provider and save outputs, latency and validation for blind scoring')]
class CompareLlm extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(LlmComparison $comparison, TenantContext $tenant): int
    {
        $workspace = $this->workspace();

        if ($workspace === null) {
            return self::FAILURE;
        }

        /** @var array<string, array{title: string, task: string, prompt: string, variables: array<string, string>, evidence: array<string, array{status: string, text: string}>}> $samples */
        $samples = require $this->option('fixture') ?: base_path('tests/Eval/fixtures/llm-comparison.php');
        $only = (array) $this->option('sample');

        if ($only !== []) {
            $samples = array_intersect_key($samples, array_flip($only));
        }

        $option = $this->option('providers');
        $providers = array_values(array_filter(array_map('trim', explode(',', is_string($option) ? $option : ''))));

        if ($samples === [] || $providers === []) {
            $this->components->error('Nothing to run: no matching samples or providers.');

            return self::FAILURE;
        }

        $this->components->info('Comparing '.implode(' vs ', $providers).' on '.count($samples).' synthetic samples (no real applications are sent).');

        $batch = $tenant->run($workspace, fn (): string => $comparison->run($samples, $providers, progress: function (string $sample, string $provider, LlmComparisonResult $result): void {
            $detail = match ($result->status) {
                'succeeded' => "{$result->model} · {$result->latency_ms} ms",
                default => $result->status.': '.mb_strimwidth((string) $result->error_message, 0, 120, '…'),
            };
            $this->components->twoColumnDetail("{$sample} · {$provider}", $detail);
        }));

        $this->components->info("Saved batch {$batch}. Score it blind under Settings → Model comparison.");

        return self::SUCCESS;
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
