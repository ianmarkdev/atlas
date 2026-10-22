<?php

use App\Domain\Ai\Clients\FakeLlmClient;
use App\Domain\Ai\LlmRequest;
use App\Domain\Ai\LlmRouter;
use App\Domain\Applications\AnswerDraft;
use App\Domain\Applications\ApplicationQuestion;
use App\Domain\Applications\JobApplication;
use App\Domain\Resumes\ResumeVariant;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function draftedApplicationOf(User $candidate): JobApplication
{
    return JobApplication::factory()->forProfile(profileOf($candidate))
        ->withJobDescription("Senior engineer.\nYou have deep PostgreSQL experience.")
        ->create(['company_name' => 'Fabrikam', 'role_title' => 'Senior Engineer']);
}

function questionToDraft(User $candidate, JobApplication $application, string $prompt = 'Tell us about your PostgreSQL work.'): ApplicationQuestion
{
    test()->actingAs($candidate)->post(route('applications.questions.store', $application), ['prompt_text' => $prompt, 'word_limit' => 150]);

    return inWorkspaceOf($candidate, fn () => ApplicationQuestion::query()->where('prompt_text', $prompt)->sole());
}

function draftOf(User $candidate, ApplicationQuestion $question): AnswerDraft
{
    return inWorkspaceOf($candidate, fn () => AnswerDraft::query()->where('application_question_id', $question->id)->with('currentVersion')->sole());
}

/**
 * @param  list<array{text: string, classification: string, evidence_ids: list<string>}>  $claims
 * @return array<string, mixed>
 */
function answerOutput(string $answer, array $claims, array $questions = []): array
{
    return ['answer' => $answer, 'segments' => [['text' => $answer, 'claims' => $claims]], 'clarification_questions' => $questions];
}

test('an AI draft is written from the evidence pack and saved as a checked new version', function () {
    $candidate = candidateWithTwoFactor();
    $application = draftedApplicationOf($candidate);
    claimOf($candidate, 'Tuned PostgreSQL indexes and queries for the billing platform');
    $question = questionToDraft($candidate, $application);
    $fake = app(LlmRouter::class)->fake(new FakeLlmClient([
        answerOutput('I tuned PostgreSQL for a billing platform (E1), cutting latency by 60%. I would bring the same care to Fabrikam [E1, E9].', [
            ['text' => 'I tuned PostgreSQL for a billing platform', 'classification' => 'verified', 'evidence_ids' => ['E1']],
            ['text' => 'I tuned PostgreSQL for a billing platform', 'classification' => 'verified', 'evidence_ids' => ['E1']],
            ['text' => 'cutting latency by 60%', 'classification' => 'verified', 'evidence_ids' => ['E1']],
            ['text' => 'I would bring the same care', 'classification' => 'hypothetical', 'evidence_ids' => ['E9']],
        ], [['question' => 'By how much did latency drop?', 'why' => 'A number makes it concrete.']]),
    ]));

    $this->actingAs($candidate)
        ->post(route('applications.questions.draft', [$application, $question]))
        ->assertInertiaFlash('toast.message', 'Writing a draft. It appears here when ready.');

    $draft = draftOf($candidate, $question);
    $prompt = $fake->requests[0]->messages[0]['content'];
    expect($prompt)->toContain('<item id="E1" status="verified">Tuned PostgreSQL indexes and queries for the billing platform</item>')
        ->and($prompt)->toContain('Tell us about your PostgreSQL work.')
        ->and($prompt)->toContain('at most 150 words')
        ->and($draft->generation_status)->toBeNull()
        ->and($draft->lock_version)->toBe(1)
        ->and($draft->body())->toBe('I tuned PostgreSQL for a billing platform, cutting latency by 60%. I would bring the same care to Fabrikam.')
        ->and($draft->currentVersion->author_type)->toBe('ai')
        ->and($draft->currentVersion->generation_run_id)->not->toBeNull()
        ->and(array_column($draft->currentVersion->grounding, 'classification'))->toBe(['verified', 'proposed', 'hypothetical'])
        ->and($draft->currentVersion->grounding[1]['flags'])->toBe(['metric_without_source'])
        ->and($draft->currentVersion->grounding[2]['flags'])->toBe(['unknown_evidence']);

    $this->actingAs($candidate)
        ->get(route('applications.questions.index', $application))
        ->assertInertia(fn (Assert $page) => $page
            ->where('questions.0.answer.ai.evidence.0.id', 'E1')
            ->where('questions.0.answer.ai.questions.0.question', 'By how much did latency drop?')
            ->where('questions.0.history.0.change_reason', 'Drafted with AI')
            ->where('hasAnalysis', false));
});

test('a cover letter draft asks for a letter of the default length', function () {
    $candidate = candidateWithTwoFactor();
    $application = draftedApplicationOf($candidate);
    $this->actingAs($candidate)->post(route('applications.questions.cover-letter', $application));
    $letter = inWorkspaceOf($candidate, fn () => ApplicationQuestion::query()->where('origin', 'cover_letter')->sole());
    $fake = app(LlmRouter::class)->fake(new FakeLlmClient([
        fn (LlmRequest $request): array => answerOutput('Dear hiring team, …', []),
    ]));

    $this->actingAs($candidate)->post(route('applications.questions.draft', [$application, $letter]));

    expect($fake->requests[0]->messages[0]['content'])->toContain('Write a cover letter for this role')
        ->and($fake->requests[0]->messages[0]['content'])->toContain('about 250 to 350 words')
        ->and($fake->requests[0]->messages[0]['content'])->toContain('(no evidence:')
        ->and(draftOf($candidate, $letter)->body())->toBe('Dear hiring team, …');
});

test('drafting is refused on an approved answer or while a draft is being written', function () {
    $candidate = candidateWithTwoFactor();
    $application = draftedApplicationOf($candidate);
    $question = questionToDraft($candidate, $application);
    $this->actingAs($candidate)->put(route('applications.questions.answer', [$application, $question]), ['body' => 'My answer.', 'lock_version' => 0]);
    $this->actingAs($candidate)->patch(route('applications.questions.approve', [$application, $question]));

    $this->actingAs($candidate)
        ->post(route('applications.questions.draft', [$application, $question]))
        ->assertSessionHasErrors('body');

    $this->actingAs($candidate)->patch(route('applications.questions.reopen', [$application, $question]));
    inWorkspaceOf($candidate, fn () => AnswerDraft::query()->where('application_question_id', $question->id)->update(['generation_status' => 'running']));

    $this->actingAs($candidate)
        ->post(route('applications.questions.draft', [$application, $question]))
        ->assertSessionHasErrors(['body' => 'A draft is already being written.']);
});

test('an unusable model answer is recorded as a failed draft and keeps the text', function () {
    $candidate = candidateWithTwoFactor();
    $application = draftedApplicationOf($candidate);
    $question = questionToDraft($candidate, $application);
    $this->actingAs($candidate)->put(route('applications.questions.answer', [$application, $question]), ['body' => 'My own words.', 'lock_version' => 0]);
    app(LlmRouter::class)->fake(new FakeLlmClient(['not json', '{"answer": ""}']));

    $this->actingAs($candidate)->post(route('applications.questions.draft', [$application, $question]));

    $draft = draftOf($candidate, $question);
    expect($draft->generation_status)->toBe('failed')
        ->and($draft->generation_error)->toContain('not usable')
        ->and($draft->body())->toBe('My own words.');
});

test('a redraft passes the instruction to the AI and records it on the version', function () {
    $candidate = candidateWithTwoFactor();
    $application = draftedApplicationOf($candidate);
    $question = questionToDraft($candidate, $application);
    $fake = app(LlmRouter::class)->fake(new FakeLlmClient([answerOutput('A shorter answer.', [])]));

    $this->actingAs($candidate)->post(route('applications.questions.draft', [$application, $question]), ['instruction' => '  Shorter, please ']);

    $draft = draftOf($candidate, $question);
    expect($fake->requests[0]->messages[0]['content'])->toContain("<instruction>\nShorter, please\n</instruction>")
        ->and($draft->currentVersion->instruction)->toBe('Shorter, please')
        ->and($draft->generation_instruction)->toBeNull();

    $this->actingAs($candidate)
        ->get(route('applications.questions.index', $application))
        ->assertInertia(fn (Assert $page) => $page->where('questions.0.history.0.change_reason', 'Drafted with AI: “Shorter, please”'));
});

test('an AI draft with unconfirmed claims is approved only after the candidate checks them', function () {
    $candidate = candidateWithTwoFactor();
    $application = draftedApplicationOf($candidate);
    $question = questionToDraft($candidate, $application);
    app(LlmRouter::class)->fake(new FakeLlmClient([
        answerOutput('I would cut costs for Fabrikam.', [['text' => 'I would cut costs', 'classification' => 'proposed', 'evidence_ids' => []]]),
    ]));
    $this->actingAs($candidate)->post(route('applications.questions.draft', [$application, $question]));

    $this->actingAs($candidate)
        ->patch(route('applications.questions.approve', [$application, $question]))
        ->assertSessionHasErrors('body');
    $this->actingAs(vaOf($candidate))
        ->patch(route('applications.questions.claims-reviewed', [$application, $question]))
        ->assertForbidden();

    $this->actingAs($candidate)->patch(route('applications.questions.claims-reviewed', [$application, $question]));
    $this->actingAs($candidate)
        ->patch(route('applications.questions.approve', [$application, $question]))
        ->assertSessionHasNoErrors();

    expect(draftOf($candidate, $question)->isApproved())->toBeTrue();
});

test('a cover letter is written from the tailored resume: its bullets lead the evidence, in a storyline', function () {
    $candidate = candidateWithTwoFactor();
    $employment = employmentOf($candidate);
    $claim = claimOf($candidate, 'Migrated card payments to a new processor', $employment);
    $variant = ResumeVariant::factory()->forProfile(profileOf($candidate))->withDocument(['experience' => [[
        'employment' => $employment->ulid, 'display_title' => null, 'description' => null, 'projects' => [],
        'bullets' => [['claim' => $claim->ulid, 'text' => 'Led the migration of card payments to a new processor']],
    ]]])->create();
    $application = draftedApplicationOf($candidate);
    $this->actingAs($candidate)->post(route('applications.resume.store', $application), ['variant' => $variant->ulid]);
    $this->actingAs($candidate)->post(route('applications.questions.cover-letter', $application));
    $letter = inWorkspaceOf($candidate, fn () => ApplicationQuestion::query()->where('origin', 'cover_letter')->sole());
    $fake = app(LlmRouter::class)->fake(new FakeLlmClient([answerOutput('Dear hiring team, …', [])]));

    $this->actingAs($candidate)->post(route('applications.questions.draft', [$application, $letter]));

    $prompt = $fake->requests[0]->messages[0]['content'];
    expect($prompt)->toContain('<item id="E1" status="verified">Led the migration of card payments to a new processor</item>')
        ->and($prompt)->toContain("1. {$employment->title} at {$employment->employer_name}: E1")
        ->and(draftOf($candidate, $letter)->currentVersion->resume_version_id)->not->toBeNull();
});

test('an answer with AI placeholders left in cannot be approved', function () {
    $candidate = candidateWithTwoFactor();
    $application = draftedApplicationOf($candidate);
    $question = questionToDraft($candidate, $application);
    $this->actingAs($candidate)->put(route('applications.questions.answer', [$application, $question]), ['body' => 'I cut costs by [confirm: % saved].', 'lock_version' => 0]);

    $this->actingAs($candidate)
        ->patch(route('applications.questions.approve', [$application, $question]))
        ->assertSessionHasErrors('body');

    expect(draftOf($candidate, $question)->isApproved())->toBeFalse();
});
