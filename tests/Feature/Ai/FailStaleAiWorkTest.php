<?php

use App\Domain\Ai\GenerationRun;
use App\Domain\Applications\AnswerDraft;
use App\Domain\Applications\ApplicationQuestion;
use App\Domain\Applications\JobAnalysis;
use App\Domain\Applications\JobApplication;
use App\Models\User;

/**
 * An analysis of a new application, in the given state.
 *
 * @param  array<string, mixed>  $attributes
 */
function staleAnalysisOf(User $candidate, array $attributes): JobAnalysis
{
    $application = JobApplication::factory()->forProfile(profileOf($candidate))->withJobDescription('Senior engineer.')->create();

    return inWorkspaceOf($candidate, fn () => JobAnalysis::query()->forceCreate([
        'job_application_id' => $application->id, 'job_posting_id' => $application->posting->id, ...$attributes,
    ]));
}

test('AI work running past the stale limit is ended; queued and recent work is left alone', function () {
    $this->travelTo('2026-11-02 09:00:00');
    $candidate = candidateWithTwoFactor();
    $stale = staleAnalysisOf($candidate, ['status' => 'running']);
    $waiting = staleAnalysisOf($candidate, ['status' => 'queued']);
    $refresh = staleAnalysisOf($candidate, ['status' => 'succeeded', 'brief' => ['summary' => 'x'], 'rematch_requested_at' => now()]);
    $application = JobApplication::factory()->forProfile(profileOf($candidate))->create();
    $this->actingAs($candidate)->post(route('applications.questions.store', $application), ['prompt_text' => 'Why us?']);
    $draft = inWorkspaceOf($candidate, fn () => AnswerDraft::query()->forceCreate([
        'application_question_id' => ApplicationQuestion::query()->sole()->id, 'status' => 'draft', 'lock_version' => 0, 'generation_status' => 'running',
    ]));
    $run = inWorkspaceOf($candidate, fn () => GenerationRun::query()->forceCreate([
        'task' => 'jd_analysis', 'provider' => 'ollama', 'model' => 'qwen2.5:3b', 'prompt_key' => 'jd_analysis', 'prompt_version' => 1,
        'status' => 'running', 'request_hash' => str_repeat('0', 64),
    ]));
    $this->travel((int) config('ai.run_stale_after_minutes') + 1)->minutes();
    $recent = staleAnalysisOf($candidate, ['status' => 'running']);

    $this->artisan('app:fail-stale-ai-work')->assertSuccessful();

    expect($stale->refresh()->only(['status', 'error_message']))->toBe(['status' => 'failed', 'error_message' => 'The analysis stopped without finishing. Try again.'])
        ->and($waiting->refresh()->status)->toBe('queued')
        ->and($recent->refresh()->status)->toBe('running')
        ->and($refresh->refresh()->rematch_requested_at)->toBeNull()
        ->and($draft->refresh()->generation_status)->toBe('failed')
        ->and($run->refresh()->only(['status', 'error_code']))->toBe(['status' => 'failed', 'error_code' => 'abandoned']);
});
