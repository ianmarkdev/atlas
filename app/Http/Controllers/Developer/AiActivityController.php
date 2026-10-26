<?php

namespace App\Http\Controllers\Developer;

use App\Domain\Ai\GenerationRun;
use App\Domain\Applications\AnswerDraftVersion;
use App\Domain\Feedback\FeedbackEvent;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Candidate-only audit of every AI call (M4 `generation_runs`): per task, how often it worked,
 * how long it took and what it used; how accurate the answer drafts were (the server's claim
 * checks and the edits people made before approving); and each run with its prompt and response
 * while the payload is retained.
 */
class AiActivityController extends Controller
{
    /**
     * The periods the page can show, in days.
     */
    public const PERIODS = [1, 7, 30, 90];

    public const STATUSES = ['running', 'succeeded', 'failed', 'invalid_output'];

    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'days' => ['nullable', 'integer', 'in:'.implode(',', self::PERIODS)],
            'task' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'in:'.implode(',', self::STATUSES)],
        ]);

        $days = (int) ($validated['days'] ?? 7);
        $since = CarbonImmutable::now()->subDays($days);
        $task = $validated['task'] ?? null;
        $status = $validated['status'] ?? null;

        $runs = GenerationRun::query()
            ->with('creator:id,name')
            ->where('created_at', '>=', $since)
            ->when($task, fn (Builder $query, string $task) => $query->where('task', $task))
            ->when($status, fn (Builder $query, string $status) => $query->where('status', $status))
            ->latest('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (GenerationRun $run): array => $this->presentRun($run));

        return Inertia::render('developer/ai-activity', [
            'filters' => ['days' => $days, 'task' => $task, 'status' => $status],
            'periods' => self::PERIODS,
            'statuses' => self::STATUSES,
            'tasks' => GenerationRun::query()->distinct()->orderBy('task')->pluck('task')->all(),
            'summary' => $this->summary($since),
            'accuracy' => $this->accuracy($since),
            'runs' => $runs,
        ]);
    }

    public function show(GenerationRun $run): Response
    {
        $run->load(['creator:id,name', 'payload']);
        $payload = $run->payload;

        return Inertia::render('developer/ai-activity-run', [
            'run' => [
                ...$this->presentRun($run),
                'prompt_key' => $run->prompt_key,
                'request_hash' => $run->request_hash,
                'idempotency_key' => $run->idempotency_key,
                'error_message' => $run->error_message,
                'updated_at' => $run->updated_at?->toIso8601String(),
            ],
            'payload' => $payload === null ? null : [
                'system' => $payload->request['system'] ?? null,
                'messages' => $payload->request['messages'] ?? [],
                'response' => $payload->response,
                'output' => $payload->output,
                'expires_at' => $payload->expires_at->toIso8601String(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentRun(GenerationRun $run): array
    {
        return [
            'ulid' => $run->ulid,
            'task' => $run->task,
            'purpose' => $run->purpose,
            'prompt_version' => $run->prompt_version,
            'provider' => $run->provider,
            'model' => $run->model,
            'status' => $run->status,
            'error_code' => $run->error_code,
            'input_tokens' => $run->input_tokens,
            'output_tokens' => $run->output_tokens,
            'cost_micros' => $run->cost_micros,
            'latency_ms' => $run->latency_ms,
            'attempts' => $run->attempts,
            'created_by' => $run->creator?->name,
            'created_at' => $run->created_at?->toIso8601String(),
        ];
    }

    /**
     * Per task: outcomes, latency of finished runs, usage and retries since `$since`.
     *
     * @return list<array{task: string, runs: int, succeeded: int, failed: int, invalid_output: int, running: int, median_latency_ms: int|null, p95_latency_ms: int|null, max_latency_ms: int|null, avg_input_tokens: int, avg_output_tokens: int, cost_micros: int, retried: int}>
     */
    private function summary(CarbonImmutable $since): array
    {
        return array_values(GenerationRun::query()
            ->where('created_at', '>=', $since)
            ->selectRaw("task,
                count(*) as runs,
                count(*) filter (where status = 'succeeded') as succeeded,
                count(*) filter (where status = 'failed') as failed,
                count(*) filter (where status = 'invalid_output') as invalid_output,
                count(*) filter (where status = 'running') as running,
                percentile_cont(0.5) within group (order by latency_ms) filter (where status <> 'running') as median_latency_ms,
                percentile_cont(0.95) within group (order by latency_ms) filter (where status <> 'running') as p95_latency_ms,
                max(latency_ms) filter (where status <> 'running') as max_latency_ms,
                coalesce(avg(input_tokens), 0) as avg_input_tokens,
                coalesce(avg(output_tokens), 0) as avg_output_tokens,
                coalesce(sum(cost_micros), 0) as cost_micros,
                count(*) filter (where attempts > 1) as retried")
            ->groupBy('task')
            ->orderBy('task')
            ->toBase()
            ->get()
            ->map(fn (object $row): array => [
                'task' => (string) $row->task,
                'runs' => (int) $row->runs,
                'succeeded' => (int) $row->succeeded,
                'failed' => (int) $row->failed,
                'invalid_output' => (int) $row->invalid_output,
                'running' => (int) $row->running,
                'median_latency_ms' => $row->median_latency_ms === null ? null : (int) round((float) $row->median_latency_ms),
                'p95_latency_ms' => $row->p95_latency_ms === null ? null : (int) round((float) $row->p95_latency_ms),
                'max_latency_ms' => $row->max_latency_ms === null ? null : (int) $row->max_latency_ms,
                'avg_input_tokens' => (int) round((float) $row->avg_input_tokens),
                'avg_output_tokens' => (int) round((float) $row->avg_output_tokens),
                'cost_micros' => (int) $row->cost_micros,
                'retried' => (int) $row->retried,
            ])
            ->all());
    }

    /**
     * Accuracy of AI answer drafts since `$since`: the claims the server checked (and corrected
     * where the model's label was not backed by evidence), and the drafts people edited.
     *
     * @return array{drafts: int, claims: int, verified: int, unsupported: int, corrected: int, edited_drafts: int, edited_words: int}
     */
    private function accuracy(CarbonImmutable $since): array
    {
        $drafts = AnswerDraftVersion::query()
            ->where('author_type', 'ai')
            ->where('created_at', '>=', $since)
            ->get(['id', 'grounding']);
        $claims = $drafts->flatMap(fn (AnswerDraftVersion $version): array => $version->grounding ?? []);

        $edits = FeedbackEvent::query()
            ->where('created_at', '>=', $since)
            ->get(['id', 'from_version_id', 'diff']);

        return [
            'drafts' => $drafts->count(),
            'claims' => $claims->count(),
            'verified' => $claims->where('classification', 'verified')->count(),
            // Not backed by the library: proposed as new facts or only hypothetical.
            'unsupported' => $claims->whereIn('classification', ['proposed', 'hypothetical'])->count(),
            'corrected' => $claims->filter(fn (array $claim): bool => $claim['flags'] !== [])->count(),
            'edited_drafts' => $edits->pluck('from_version_id')->unique()->count(),
            'edited_words' => $edits->sum(fn (FeedbackEvent $event): int => collect($event->diff)
                ->where('op', '!=', 'equal')
                ->sum(fn (array $part): int => count(preg_split('/\s+/u', trim((string) $part['text']), -1, PREG_SPLIT_NO_EMPTY) ?: []))),
        ];
    }
}
