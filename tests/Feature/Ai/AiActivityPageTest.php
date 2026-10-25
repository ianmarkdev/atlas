<?php

use App\Domain\Ai\GenerationPayload;
use App\Domain\Ai\GenerationRun;
use App\Models\User;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A finished run in the workspace of `$user`, as AiGateway records it.
 *
 * @param  array<string, mixed>  $attributes
 */
function generationRunFor(User $user, array $attributes = []): GenerationRun
{
    return inWorkspaceOf($user, function () use ($attributes): GenerationRun {
        $run = new GenerationRun;
        $run->forceFill([
            'task' => 'jd_analysis',
            'prompt_key' => 'jd_analysis',
            'prompt_version' => 1,
            'provider' => 'ollama',
            'model' => 'qwen3:8b',
            'request_hash' => str_repeat('a', 64),
            'input_tokens' => 100,
            'output_tokens' => 50,
            'latency_ms' => 1000,
            'attempts' => 1,
            'status' => 'succeeded',
            ...$attributes,
        ])->save();

        return $run;
    });
}

test('the candidate sees outcomes, latency and usage per task for the period', function () {
    $candidate = candidateWithTwoFactor();
    foreach ([1000, 2000, 3000, 4000] as $latency) {
        generationRunFor($candidate, ['latency_ms' => $latency]);
    }
    generationRunFor($candidate, ['status' => 'invalid_output', 'attempts' => 2, 'latency_ms' => 9000, 'error_code' => 'invalid_output']);
    generationRunFor($candidate, ['status' => 'running', 'latency_ms' => 0]);
    generationRunFor($candidate, ['task' => 'answer_writing', 'status' => 'failed', 'error_code' => 'unavailable']);
    generationRunFor($candidate, ['created_at' => now()->subDays(10)]);
    generationRunFor(candidateWithTwoFactor(), ['task' => 'claim_check']);

    $this->actingAs($candidate)->get(route('ai-activity.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/ai-activity')
            ->where('filters.days', 7)
            ->where('tasks', ['answer_writing', 'jd_analysis'])
            ->has('summary', 2)
            ->where('summary.0.task', 'answer_writing')
            ->where('summary.0.failed', 1)
            ->where('summary.1.task', 'jd_analysis')
            ->where('summary.1.runs', 6)
            ->where('summary.1.succeeded', 4)
            ->where('summary.1.invalid_output', 1)
            ->where('summary.1.running', 1)
            ->where('summary.1.retried', 1)
            // Over the five finished runs; the running one has no latency yet.
            ->where('summary.1.median_latency_ms', 3000)
            ->where('summary.1.max_latency_ms', 9000)
            ->where('runs.total', 7));

    $this->actingAs($candidate)->get(route('ai-activity.index', ['days' => 30, 'task' => 'jd_analysis', 'status' => 'succeeded']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.1.runs', 7)
            ->where('runs.total', 5)
            ->where('runs.data.0.status', 'succeeded'));
});

test('a run shows its prompt and response while the payload is kept', function () {
    $candidate = candidateWithTwoFactor();
    $run = generationRunFor($candidate, ['created_by' => $candidate->id]);
    $expired = generationRunFor($candidate);
    inWorkspaceOf($candidate, function () use ($run): void {
        (new GenerationPayload)->forceFill([
            'generation_run_id' => $run->id,
            'request' => ['model' => 'qwen3:8b', 'system' => 'You analyse job descriptions.', 'messages' => [['role' => 'user', 'content' => 'Senior PHP role']]],
            'response' => '{"summary":"PHP role"}',
            'output' => ['summary' => 'PHP role'],
            'expires_at' => now()->addDays(30),
        ])->save();
    });

    $this->actingAs($candidate)->get(route('ai-activity.show', $run))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/ai-activity-run')
            ->where('run.ulid', $run->ulid)
            ->where('run.created_by', $candidate->name)
            ->where('payload.system', 'You analyse job descriptions.')
            ->where('payload.messages.0.content', 'Senior PHP role')
            ->where('payload.output.summary', 'PHP role'));

    $this->actingAs($candidate)->get(route('ai-activity.show', $expired))
        ->assertInertia(fn (Assert $page) => $page->where('payload', null));
});

test('only the candidate of the workspace can see AI activity', function () {
    $candidate = candidateWithTwoFactor();
    $run = generationRunFor($candidate);

    $va = vaOf($candidate);
    $this->actingAs($va)->get(route('ai-activity.index'))->assertForbidden();
    $this->actingAs($va)->get(route('ai-activity.show', $run))->assertForbidden();

    $this->actingAs(candidateWithTwoFactor())->get(route('ai-activity.show', $run))->assertNotFound();
    $this->actingAs($candidate)->get(route('ai-activity.index', ['days' => 3]))->assertSessionHasErrors('days');
    $this->actingAs($candidate)->get(route('ai-activity.show', Str::ulid()->toString()))->assertNotFound();
});
