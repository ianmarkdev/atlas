<?php

namespace App\Console\Commands;

use App\Domain\Ai\AiBudgetExceeded;
use App\Domain\Ai\AiGateway;
use App\Domain\Ai\InvalidModelOutput;
use App\Domain\Ai\LlmConfigurationError;
use App\Domain\Ai\LlmUnavailable;
use App\Domain\Applications\EvidencePackBuilder;
use App\Domain\Feedback\FeedbackAnalyser;
use App\Domain\Knowledge\WritingPreference;
use App\Domain\Knowledge\WritingPreferenceDrafts;
use App\Domain\Knowledge\WritingPreferenceKind;
use App\Domain\Shared\WordDiff;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\Workspace;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

#[Signature('app:eval-feedback
    {--provider= : Force a provider (default: the feedback_analysis task\'s configured one)}
    {--prompt-version= : Run this version of the feedback_analysis prompt (default: the latest)}
    {--limit=50 : At most this many edits}
    {--workspace= : Workspace ULID (default: the only workspace)}
    {--fixture= : Fixture file (default tests/Eval/fixtures/feedback-analysis.php)}')]
#[Description('Measure how the AI reads edits: rules found, held and active rules matched (same_as), contradictions (conflicts_with), one-offs left alone and drafts scope')]
class EvalFeedback extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(AiGateway $gateway, TenantContext $tenant): int
    {
        $workspace = $this->workspace();

        if ($workspace === null) {
            return self::FAILURE;
        }

        $fixture = $this->option('fixture');
        $cases = require is_string($fixture) && $fixture !== '' ? $fixture : base_path('tests/Eval/fixtures/feedback-analysis.php');

        if (! is_array($cases) || $cases === []) {
            $this->components->error('Nothing to evaluate: the fixture has no edits.');

            return self::FAILURE;
        }

        $cases = array_slice($cases, 0, max(1, (int) $this->option('limit')), true);
        $provider = is_string($this->option('provider')) && $this->option('provider') !== '' ? $this->option('provider') : null;
        $promptVersion = is_numeric($this->option('prompt-version')) ? (int) $this->option('prompt-version') : null;
        $this->components->info('Reading '.count($cases).' edits'.($provider ? " with {$provider}" : '').'. Nothing is saved to your rules or suggestions.');

        $results = [];

        // Generation runs are rolled back: an eval must not count against the budget or clutter history.
        DB::beginTransaction();

        try {
            foreach ($cases as $key => $case) {
                $results[$key] = $tenant->run($workspace, fn (): array => $this->evaluate($gateway, self::caseOf($case), $provider, $promptVersion));
                $this->components->twoColumnDetail((string) $key, $this->line_($results[$key]));
            }
        } finally {
            DB::rollBack();
        }

        $this->report($results);

        return collect($results)->every(fn (array $result): bool => $result['status'] === 'succeeded') ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array{category: string, question: string, cover_letter: bool, original: string, edited: string, evidence: list<string>, rules: array<int, array{0: string, 1: string, 2?: string}>, held: array<string, string>, expect: array<string, mixed>}  $case
     * @return array<string, mixed>
     */
    private function evaluate(AiGateway $gateway, array $case, ?string $provider, ?int $promptVersion): array
    {
        $rules = [];

        foreach ($case['rules'] as $id => $rule) {
            $rules[] = (new WritingPreference)->forceFill([
                'id' => $id,
                'kind' => WritingPreferenceKind::from($rule[0]),
                'rule' => $rule[1],
                'drafts' => WritingPreferenceDrafts::tryFrom($rule[2] ?? '') ?? WritingPreferenceDrafts::All,
            ]);
        }

        $pack = [];

        foreach ($case['evidence'] as $index => $text) {
            $pack['E'.($index + 1)] = ['ref' => null, 'status' => 'verified', 'text' => $text];
        }

        try {
            // The same variables FeedbackAnalyser::variables() builds from a real edit.
            $result = $gateway->run('feedback_analysis', 'feedback_analysis', [
                'question' => $case['cover_letter'] ? __('Cover letter') : $case['question'],
                'editor' => 'the candidate',
                'original' => $case['original'],
                'edited' => $case['edited'],
                'changes' => WordDiff::render(WordDiff::compute($case['original'], $case['edited'])),
                'evidence' => EvidencePackBuilder::render($pack),
                'writing_rules' => WritingPreference::renderWithIds($rules),
                'held_rules' => FeedbackAnalyser::renderHeldRules($case['held']),
            ], purpose: 'eval', provider: $provider, promptVersion: $promptVersion);
        } catch (InvalidModelOutput $exception) {
            return ['status' => 'invalid_output', 'category' => $case['category'], 'error' => implode('; ', array_slice($exception->errors, 0, 3))];
        } catch (LlmUnavailable|LlmConfigurationError|AiBudgetExceeded $exception) {
            return ['status' => 'failed', 'category' => $case['category'], 'error' => $exception->getMessage()];
        }

        $preferences = array_values(array_filter((array) ($result->data['items'] ?? []), fn (mixed $item): bool => is_array($item) && ($item['type'] ?? null) === 'writing_preference'));
        $expect = $case['expect'];

        return [
            'status' => 'succeeded',
            'category' => $case['category'],
            'model' => $result->run->model,
            'prompt_version' => $result->run->prompt_version,
            'latency_ms' => $result->run->latency_ms,
            'attempts' => $result->run->attempts,
            'rules' => array_map(fn (array $item): array => array_intersect_key($item, array_flip(['rule', 'rule_kind', 'same_as', 'conflicts_with', 'drafts', 'confidence'])), $preferences),
            'rule_expected' => $expect['rule'],
            'rule_found' => $preferences !== [],
            'same_as_ok' => self::idCorrect($preferences, 'same_as', $expect),
            'wrong_same_as' => self::wrongIds($preferences, 'same_as', $expect),
            'conflicts_ok' => self::idCorrect($preferences, 'conflicts_with', $expect),
            'wrong_conflicts' => self::wrongIds($preferences, 'conflicts_with', $expect),
            'drafts_ok' => ! array_key_exists('drafts', $expect) || $preferences === [] ? null : in_array($expect['drafts'], array_column($preferences, 'drafts'), true),
        ];
    }

    /**
     * Whether a reported rule names the expected id (or none names one, when null is expected).
     * Null when there is nothing to judge: no expectation, or no rule reported where none should match.
     *
     * @param  list<array<string, mixed>>  $preferences
     * @param  array<string, mixed>  $expect
     */
    private static function idCorrect(array $preferences, string $field, array $expect): ?bool
    {
        if (! array_key_exists($field, $expect)) {
            return null;
        }

        if ($expect[$field] === null) {
            return $preferences === [] ? null : array_filter(array_column($preferences, $field)) === [];
        }

        return in_array($expect[$field], array_column($preferences, $field), true);
    }

    /**
     * Ids named where another id or none was expected: the mistakes that would promote, count or
     * replace the wrong rule.
     *
     * @param  list<array<string, mixed>>  $preferences
     * @param  array<string, mixed>  $expect
     * @return list<string>
     */
    private static function wrongIds(array $preferences, string $field, array $expect): array
    {
        if (! array_key_exists($field, $expect)) {
            return [];
        }

        return array_values(array_filter(
            array_map(strval(...), array_filter(array_column($preferences, $field))),
            fn (string $id): bool => $id !== $expect[$field],
        ));
    }

    /**
     * @param  array<string, array<string, mixed>>  $results
     */
    private function report(array $results): void
    {
        $succeeded = array_filter($results, fn (array $result): bool => $result['status'] === 'succeeded');
        $score = function (string $key) use ($succeeded): string {
            $judged = array_filter(array_column($succeeded, $key), fn (mixed $value): bool => $value !== null);

            return count(array_filter($judged)).'/'.count($judged);
        };
        $latencies = array_column($succeeded, 'latency_ms');
        sort($latencies);
        $withRule = array_filter($succeeded, fn (array $result): bool => $result['rule_expected'] === true);
        $oneOffs = array_filter($succeeded, fn (array $result): bool => $result['rule_expected'] === false);

        $summary = [
            'valid_output' => count($succeeded).'/'.count($results),
            'repairs_needed' => count(array_filter($succeeded, fn (array $result): bool => $result['attempts'] > 1)),
            'median_latency_s' => $latencies === [] ? null : round($latencies[intdiv(count($latencies), 2)] / 1000, 1),
            'rule_found' => count(array_filter($withRule, fn (array $result): bool => $result['rule_found'])).'/'.count($withRule),
            'one_offs_left_alone' => count(array_filter($oneOffs, fn (array $result): bool => ! $result['rule_found'])).'/'.count($oneOffs),
            'same_as_correct' => $score('same_as_ok'),
            'wrong_same_as' => array_sum(array_map(fn (array $result): int => count($result['wrong_same_as']), $succeeded)),
            'conflicts_correct' => $score('conflicts_ok'),
            'wrong_conflicts' => array_sum(array_map(fn (array $result): int => count($result['wrong_conflicts']), $succeeded)),
            'drafts_correct' => $score('drafts_ok'),
        ];

        $this->newLine();
        $this->table(['Metric', 'Value'], array_map(fn (string $key, mixed $value): array => [str_replace('_', ' ', $key), $value ?? '—'], array_keys($summary), $summary));

        foreach ($results as $key => $result) {
            if (($result['wrong_same_as'] ?? []) !== [] || ($result['wrong_conflicts'] ?? []) !== []) {
                $this->line("  {$key}: wrong same_as ".json_encode($result['wrong_same_as']).', wrong conflicts_with '.json_encode($result['wrong_conflicts']));
            }
        }

        $path = 'evals/feedback-'.now()->format('Ymd-His').'.json';
        Storage::disk('local')->put($path, (string) json_encode(['summary' => $summary, 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->components->info("Report saved to storage/app/private/{$path}");
    }

    /**
     * One eval case with defaults.
     *
     * @return array{category: string, question: string, cover_letter: bool, original: string, edited: string, evidence: list<string>, rules: array<int, array{0: string, 1: string, 2?: string}>, held: array<string, string>, expect: array<string, mixed>}
     */
    private static function caseOf(mixed $case): array
    {
        $case = is_array($case) ? $case : [];

        return [
            'category' => is_string($case['category'] ?? null) ? $case['category'] : 'other',
            'question' => is_string($case['question'] ?? null) ? $case['question'] : '',
            'cover_letter' => ($case['cover_letter'] ?? false) === true,
            'original' => is_string($case['original'] ?? null) ? $case['original'] : '',
            'edited' => is_string($case['edited'] ?? null) ? $case['edited'] : '',
            'evidence' => array_values(array_filter((array) ($case['evidence'] ?? []), is_string(...))),
            'rules' => array_filter((array) ($case['rules'] ?? []), fn (mixed $rule, mixed $id): bool => is_int($id) && is_array($rule) && count($rule) >= 2, ARRAY_FILTER_USE_BOTH),
            'held' => array_filter((array) ($case['held'] ?? []), is_string(...)),
            'expect' => [...['rule' => true], ...(array) ($case['expect'] ?? [])],
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function line_(array $result): string
    {
        if ($result['status'] !== 'succeeded') {
            return "{$result['status']}: ".mb_strimwidth((string) $result['error'], 0, 100, '…');
        }

        $mark = fn (?bool $ok): string => match ($ok) {
            true => 'ok',
            false => 'WRONG',
            null => '—',
        };

        return sprintf(
            '%.1f s · %s · %d rule(s) · same_as %s · conflicts %s · drafts %s%s',
            $result['latency_ms'] / 1000,
            $result['category'],
            count($result['rules']),
            $mark($result['same_as_ok']),
            $mark($result['conflicts_ok']),
            $mark($result['drafts_ok']),
            $result['rule_found'] === $result['rule_expected'] ? '' : ($result['rule_expected'] ? ' · rule MISSED' : ' · rule NOT EXPECTED'),
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
