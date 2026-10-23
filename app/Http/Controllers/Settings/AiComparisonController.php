<?php

namespace App\Http\Controllers\Settings;

use App\Domain\Ai\LlmComparisonResult;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Candidate-only blind scoring of model comparison runs (M4). Runs are started from the CLI
 * (`php artisan app:compare-llm`); this page shows outputs side by side under letters A/B/…
 * and reveals the provider once an output is scored.
 */
class AiComparisonController extends Controller
{
    public function index(Request $request): Response
    {
        $batches = LlmComparisonResult::query()
            ->selectRaw('batch, min(created_at) as started_at, count(*) as results, count(rated_at) as rated')
            ->groupBy('batch')
            ->orderByDesc('started_at')
            ->limit(20)
            ->get()
            ->map(fn (LlmComparisonResult $row): array => [
                'batch' => $row->batch,
                'started_at' => (string) $row->getAttribute('started_at'),
                'results' => (int) $row->getAttribute('results'),
                'rated' => (int) $row->getAttribute('rated'),
            ])
            ->values();

        $batch = $request->string('batch')->toString() ?: $batches->first()['batch'] ?? null;
        $results = $batch === null ? collect() : LlmComparisonResult::query()->where('batch', $batch)->orderBy('sample_key')->orderBy('blind_label')->get();
        $samples = $this->samples();

        return Inertia::render('settings/ai-comparisons', [
            'batches' => $batches->all(),
            'batch' => $batch,
            'samples' => $results->groupBy('sample_key')->map(fn (Collection $group, string $key): array => [
                'key' => $key,
                'title' => $samples[$key]['title'] ?? $key,
                'expectations' => $samples[$key]['expectations'] ?? [],
                'task' => $group->first()?->task,
                'results' => $group->map(fn (LlmComparisonResult $result): array => $this->present($result))->values()->all(),
            ])->values()->all(),
            'summary' => $this->summary($results),
        ]);
    }

    public function rate(Request $request, LlmComparisonResult $result): RedirectResponse
    {
        $validated = $request->validate([
            ...collect(LlmComparisonResult::SCORES)->mapWithKeys(fn (string $score): array => [$score => ['required', 'integer', 'between:1,5']])->all(),
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $result->forceFill([
            ...collect(LlmComparisonResult::SCORES)->mapWithKeys(fn (string $score): array => ["score_{$score}" => $validated[$score]])->all(),
            // Scored on every dimension of the current rubric, whatever it was run under.
            'rubric_version' => LlmComparisonResult::RUBRIC_VERSION,
            'notes' => $validated['notes'] ?? null,
            'rated_by' => $request->user()->id,
            'rated_at' => now(),
        ])->save();

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(LlmComparisonResult $result): array
    {
        $rated = $result->isRated();

        return [
            'ulid' => $result->ulid,
            'label' => $result->blind_label,
            // Hidden until scored, so the scores are blind.
            'provider' => $rated ? $result->provider : null,
            'model' => $rated ? $result->model : null,
            'status' => $result->status,
            'output' => $result->output,
            'validation_errors' => $result->validation_errors,
            'grounding' => $result->grounding_flags,
            'checks' => $result->checks,
            'prompt_version' => $rated ? $result->prompt_version : null,
            'error_message' => $result->error_message,
            'latency_ms' => $result->latency_ms,
            'tokens' => $result->input_tokens === null ? null : ['input' => $result->input_tokens, 'output' => $result->output_tokens],
            'scores' => $rated
                ? collect(LlmComparisonResult::SCORES)->mapWithKeys(fn (string $score): array => [$score => $result->getAttribute("score_{$score}")])->all()
                : null,
            'notes' => $result->notes,
        ];
    }

    /**
     * Average scores and median latency per provider, over scored results only.
     *
     * @param  Collection<int, LlmComparisonResult>  $results
     * @return list<array<string, string|int|float|null>> provider, rated, an average per score, median_latency_ms, downgraded_claims
     */
    private function summary(Collection $results): array
    {
        $rated = $results->filter(fn (LlmComparisonResult $result): bool => $result->isRated());

        return array_values($rated->groupBy('provider')->map(function (Collection $group, string $provider): array {
            $latencies = $group->pluck('latency_ms')->filter()->sort()->values();
            $average = fn (string $score): ?float => $group->avg("score_{$score}") === null ? null : round((float) $group->avg("score_{$score}"), 2);

            return [
                'provider' => $provider,
                'rated' => $group->count(),
                ...collect(LlmComparisonResult::SCORES)->mapWithKeys(fn (string $score): array => [$score => $average($score)])->all(),
                'median_latency_ms' => $latencies->isEmpty() ? null : (int) $latencies[intdiv($latencies->count(), 2)],
                'downgraded_claims' => $group->sum(fn (LlmComparisonResult $result): int => collect($result->grounding_flags)
                    ->filter(fn (array $claim): bool => ($claim['flags'] ?? []) !== [])->count()),
            ];
        })->all());
    }

    /**
     * @return array<string, array{title: string, expectations?: list<string>}>
     */
    private function samples(): array
    {
        $samples = require base_path('tests/Eval/fixtures/llm-comparison.php');

        return is_array($samples) ? $samples : [];
    }
}
