<?php

namespace App\Console\Commands;

use App\Domain\Ai\AiBudgetExceeded;
use App\Domain\Ai\AiGateway;
use App\Domain\Ai\InvalidModelOutput;
use App\Domain\Ai\LlmConfigurationError;
use App\Domain\Ai\LlmUnavailable;
use App\Domain\Applications\BriefChecker;
use App\Domain\Applications\JdCollection;
use App\Domain\Applications\JobApplication;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\Workspace;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

#[Signature('app:eval-analysis
    {--provider= : Force a provider (default: the jd_analysis task\'s configured one)}
    {--collection= : Run on the job descriptions of this collection (ULID) instead of the fixture}
    {--limit=10 : At most this many job descriptions}
    {--workspace= : Workspace ULID (default: the only workspace)}
    {--fixture= : Fixture file (default tests/Eval/fixtures/jd-analysis.php)}')]
#[Description('Measure job-description analysis quality: valid output, must-have recall, nice-vs-must mistakes, quote grounding, questions and injection detection')]
class EvalAnalysis extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(AiGateway $gateway, BriefChecker $checker, TenantContext $tenant): int
    {
        $workspace = $this->workspace();

        if ($workspace === null) {
            return self::FAILURE;
        }

        $cases = $tenant->run($workspace, fn (): ?array => $this->cases());

        if ($cases === null || $cases === []) {
            $this->components->error('Nothing to evaluate: no job descriptions found.');

            return self::FAILURE;
        }

        $cases = array_slice($cases, 0, max(1, (int) $this->option('limit')), true);
        $provider = is_string($this->option('provider')) && $this->option('provider') !== '' ? $this->option('provider') : null;
        $this->components->info('Analysing '.count($cases).' job descriptions'.($provider ? " with {$provider}" : '').'. Nothing is saved to your applications.');

        $results = [];

        // Generation runs are rolled back: an eval must not count against the budget or clutter history.
        DB::beginTransaction();

        try {
            foreach ($cases as $key => $case) {
                $results[$key] = $tenant->run($workspace, fn (): array => $this->evaluate($gateway, $checker, self::caseOf($case), $provider));
                $this->components->twoColumnDetail((string) $key, $this->line_($results[$key]));
            }
        } finally {
            DB::rollBack();
        }

        $this->report($results);

        return collect($results)->every(fn (array $result): bool => $result['status'] === 'succeeded') ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array{company: string, role: string, text: string, notes: string, must: list<string>, nice: list<string>, questions: ?int, injection: ?bool}  $case
     * @return array<string, mixed>
     */
    private function evaluate(AiGateway $gateway, BriefChecker $checker, array $case, ?string $provider): array
    {
        try {
            $result = $gateway->run('jd_analysis', 'jd_analysis', [
                'company_name' => $case['company'],
                'role_title' => $case['role'],
                'job_description' => $case['text'],
                'application_notes' => $case['notes'] === '' ? '(none)' : $case['notes'],
            ], purpose: 'eval', provider: $provider);
        } catch (InvalidModelOutput $exception) {
            return ['status' => 'invalid_output', 'error' => implode('; ', array_slice($exception->errors, 0, 3))];
        } catch (LlmUnavailable|LlmConfigurationError|AiBudgetExceeded $exception) {
            return ['status' => 'failed', 'error' => $exception->getMessage()];
        }

        $brief = $checker->check($result->data ?? [], $case['text'], $case['notes']);
        $musts = (array) ($brief['must_haves'] ?? []);
        $nices = (array) ($brief['nice_to_haves'] ?? []);
        $quotes = array_column([...$musts, ...$nices], 'quote_check');
        $expectedMust = $case['must'];
        $expectedNice = $case['nice'];

        return [
            'status' => 'succeeded',
            'model' => $result->run->model,
            'latency_ms' => $result->run->latency_ms,
            'attempts' => $result->run->attempts,
            'must_count' => count($musts),
            'nice_count' => count($nices),
            'must_recall' => $expectedMust === [] ? null : count(array_filter($expectedMust, fn (string $topic): bool => self::mentions($musts, $topic))) / count($expectedMust),
            'missed_must' => array_values(array_filter($expectedMust, fn (string $topic): bool => ! self::mentions($musts, $topic))),
            'nice_as_must' => array_values(array_filter($expectedNice, fn (string $topic): bool => self::mentions($musts, $topic) && ! self::mentions($nices, $topic))),
            'quotes_grounded' => $quotes === [] ? null : count(array_filter($quotes, fn (string $check): bool => in_array($check, ['found', 'close'], true))) / count($quotes),
            'questions_found' => count((array) ($brief['questions'] ?? [])),
            'questions' => $brief['questions'],
            'dropped_questions' => $brief['dropped_questions'],
            'injection_warnings' => $brief['injection_warnings'],
            'questions_expected' => $case['questions'],
            'injection_expected' => $case['injection'],
            'injection_model' => ((array) ($brief['injection_warnings'] ?? [])) !== [],
            // Only the server's findings hold questions back; the model's are hints.
            'injection_server' => ((array) ($brief['server_injection_warnings'] ?? [])) !== [],
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $results
     */
    private function report(array $results): void
    {
        $succeeded = array_filter($results, fn (array $result): bool => $result['status'] === 'succeeded');
        $mean = function (string $key) use ($succeeded): ?float {
            $values = array_values(array_filter(array_column($succeeded, $key), fn (mixed $value): bool => $value !== null));

            return $values === [] ? null : array_sum($values) / count($values);
        };
        $latencies = array_column($succeeded, 'latency_ms');
        sort($latencies);
        $injectionCases = array_filter($succeeded, fn (array $result): bool => $result['injection_expected'] === true);
        $questionCases = array_filter($succeeded, fn (array $result): bool => $result['questions_expected'] !== null);

        $summary = [
            'valid_output' => count($succeeded).'/'.count($results),
            'repairs_needed' => count(array_filter($succeeded, fn (array $result): bool => $result['attempts'] > 1)),
            'median_latency_s' => $latencies === [] ? null : round($latencies[intdiv(count($latencies), 2)] / 1000, 1),
            'must_have_recall' => $mean('must_recall') === null ? null : round($mean('must_recall'), 2),
            'nice_listed_as_must' => array_sum(array_map(fn (array $result): int => count($result['nice_as_must']), $succeeded)),
            'quotes_grounded' => $mean('quotes_grounded') === null ? null : round($mean('quotes_grounded'), 2),
            'questions_exact' => count(array_filter($questionCases, fn (array $result): bool => $result['questions_found'] === $result['questions_expected'])).'/'.count($questionCases),
            'injection_caught_by_model' => count(array_filter($injectionCases, fn (array $result): bool => $result['injection_model'])).'/'.count($injectionCases),
            'injection_caught_by_server' => count(array_filter($injectionCases, fn (array $result): bool => $result['injection_server'])).'/'.count($injectionCases),
            'false_injection_alarms_by_model' => count(array_filter($succeeded, fn (array $result): bool => $result['injection_expected'] === false && $result['injection_model'])),
            'false_injection_alarms_by_server' => count(array_filter($succeeded, fn (array $result): bool => $result['injection_expected'] === false && $result['injection_server'])),
        ];

        $this->newLine();
        $this->table(['Metric', 'Value'], array_map(fn (string $key, mixed $value): array => [str_replace('_', ' ', $key), $value ?? '—'], array_keys($summary), $summary));

        foreach ($results as $key => $result) {
            if (($result['missed_must'] ?? []) !== [] || ($result['nice_as_must'] ?? []) !== []) {
                $this->line("  {$key}: missed ".json_encode($result['missed_must']).', nice listed as must '.json_encode($result['nice_as_must']));
            }
        }

        $path = 'evals/analysis-'.now()->format('Ymd-His').'.json';
        Storage::disk('local')->put($path, (string) json_encode(['summary' => $summary, 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->components->info("Report saved to storage/app/private/{$path}");
    }

    /**
     * @return array<string, array<string, mixed>>|null
     */
    private function cases(): ?array
    {
        $collectionUlid = $this->option('collection');

        if (! is_string($collectionUlid) || $collectionUlid === '') {
            $fixture = $this->option('fixture');
            $cases = require is_string($fixture) && $fixture !== '' ? $fixture : base_path('tests/Eval/fixtures/jd-analysis.php');

            return is_array($cases) ? $cases : null;
        }

        $collection = JdCollection::query()->where('ulid', $collectionUlid)->first();

        if ($collection === null) {
            $this->components->error('No collection with that ULID.');

            return null;
        }

        // Real job descriptions have no expectations: the report shows validity, grounding, latency and injection only.
        return $collection->applications()->with('posting')->get()
            ->filter(fn (JobApplication $application): bool => $application->posting !== null)
            ->mapWithKeys(fn (JobApplication $application): array => [$application->ulid => [
                'company' => $application->company_name,
                'role' => $application->role_title,
                'text' => (string) $application->jobDescription(),
                'notes' => (string) $application->notes,
            ]])->all();
    }

    /**
     * One eval case with defaults, from a fixture entry or a real application.
     *
     * @return array{company: string, role: string, text: string, notes: string, must: list<string>, nice: list<string>, questions: ?int, injection: ?bool}
     */
    private static function caseOf(mixed $case): array
    {
        $case = is_array($case) ? $case : [];
        $strings = fn (mixed $list): array => array_values(array_filter((array) $list, is_string(...)));

        return [
            'company' => is_string($case['company'] ?? null) ? $case['company'] : '',
            'role' => is_string($case['role'] ?? null) ? $case['role'] : '',
            'text' => is_string($case['text'] ?? null) ? $case['text'] : '',
            'notes' => is_string($case['notes'] ?? null) ? $case['notes'] : '',
            'must' => $strings($case['must'] ?? []),
            'nice' => $strings($case['nice'] ?? []),
            'questions' => is_int($case['questions'] ?? null) ? $case['questions'] : null,
            'injection' => is_bool($case['injection'] ?? null) ? $case['injection'] : null,
        ];
    }

    /**
     * @param  array<int, mixed>  $items
     */
    private static function mentions(array $items, string $topic): bool
    {
        $pattern = '/\b'.preg_quote(mb_strtolower($topic), '/').'/u';

        foreach ($items as $item) {
            $text = mb_strtolower(((string) ($item['requirement'] ?? '')).' '.((string) ($item['quote'] ?? '')));

            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function line_(array $result): string
    {
        if ($result['status'] !== 'succeeded') {
            return "{$result['status']}: ".mb_strimwidth((string) $result['error'], 0, 100, '…');
        }

        return sprintf(
            '%.1f s · %d must / %d nice · recall %s · quotes %s%s',
            $result['latency_ms'] / 1000,
            $result['must_count'],
            $result['nice_count'],
            $result['must_recall'] === null ? '—' : number_format($result['must_recall'], 2),
            $result['quotes_grounded'] === null ? '—' : number_format($result['quotes_grounded'], 2),
            $result['attempts'] > 1 ? ' · repaired' : '',
        );
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
