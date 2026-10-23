<?php

use App\Domain\Ai\Clients\FakeLlmClient;
use App\Domain\Ai\GenerationPayload;
use App\Domain\Ai\LlmRouter;
use App\Domain\Applications\AnswerDraft;
use App\Domain\Applications\AnswerDraftVersion;
use App\Domain\Applications\ApplicationQuestion;
use App\Domain\Applications\JobApplication;
use App\Models\User;

/**
 * An application with one question answered by the AI.
 */
function applicationWithAiAnswer(User $candidate, string $company): JobApplication
{
    $application = JobApplication::factory()->forProfile(profileOf($candidate))
        ->withJobDescription('Senior engineer. PostgreSQL.')
        ->create(['company_name' => $company, 'role_title' => 'Senior Engineer']);
    test()->actingAs($candidate)->post(route('applications.questions.store', $application), ['prompt_text' => "Why {$company}?", 'word_limit' => 150]);
    $question = inWorkspaceOf($candidate, fn () => ApplicationQuestion::query()->where('job_application_id', $application->id)->sole());
    app(LlmRouter::class)->fake(new FakeLlmClient([['answer' => 'An answer.', 'claims' => [], 'clarification_questions' => []]]));
    test()->actingAs($candidate)->post(route('applications.questions.draft', [$application, $question]));

    return $application;
}

function aiPayloadOf(User $candidate, JobApplication $application): ?GenerationPayload
{
    return inWorkspaceOf($candidate, fn () => GenerationPayload::query()
        ->whereIn('generation_run_id', AnswerDraftVersion::query()
            ->whereIn('answer_draft_id', AnswerDraft::query()->whereHas('question', fn ($query) => $query->where('job_application_id', $application->id))->select('id'))
            ->select('generation_run_id'))
        ->first());
}

test('sending an application keeps its AI payloads for a year, while unsent work still expires', function () {
    $this->travelTo('2026-11-02 09:00:00');
    $candidate = candidateWithTwoFactor();
    $sent = applicationWithAiAnswer($candidate, 'Fabrikam');
    $unsent = applicationWithAiAnswer($candidate, 'Contoso');

    $this->actingAs($candidate)->patch(route('applications.stage', $sent), ['stage' => 'applied'])->assertRedirect();

    expect(aiPayloadOf($candidate, $sent)->expires_at->toDateTimeString())->toBe('2027-11-02 09:00:00')
        ->and(aiPayloadOf($candidate, $unsent)->expires_at->toDateTimeString())->toBe('2026-11-16 09:00:00');

    $this->travelTo('2026-12-01 09:00:00');
    $this->artisan('model:prune', ['--model' => [GenerationPayload::class]])->assertSuccessful();

    expect(aiPayloadOf($candidate, $sent))->not->toBeNull()
        ->and(aiPayloadOf($candidate, $unsent))->toBeNull();
});

test('moving to a later submitted stage lengthens what is kept, and never shortens it', function () {
    $this->travelTo('2026-11-02 09:00:00');
    $candidate = candidateWithTwoFactor();
    $application = applicationWithAiAnswer($candidate, 'Fabrikam');
    $this->actingAs($candidate)->patch(route('applications.stage', $application), ['stage' => 'applied']);

    $this->travelTo('2026-12-02 09:00:00');
    $this->actingAs($candidate)->patch(route('applications.stage', $application), ['stage' => 'interviewing']);
    $lengthened = aiPayloadOf($candidate, $application)->expires_at->toDateTimeString();

    config(['ai.submitted_payload_retention_days' => 30]);
    $this->actingAs($candidate)->patch(route('applications.stage', $application), ['stage' => 'offer']);

    expect($lengthened)->toBe('2027-12-02 09:00:00')
        ->and(aiPayloadOf($candidate, $application)->expires_at->toDateTimeString())->toBe('2027-12-02 09:00:00');
});

test('a stage that is not a submission keeps the short retention', function () {
    $this->travelTo('2026-11-02 09:00:00');
    $candidate = candidateWithTwoFactor();
    $application = applicationWithAiAnswer($candidate, 'Fabrikam');

    $this->actingAs($candidate)->patch(route('applications.stage', $application), ['stage' => 'preparing'])->assertRedirect();

    expect(aiPayloadOf($candidate, $application)->expires_at->toDateTimeString())->toBe('2026-11-16 09:00:00');
});
