<?php

use App\Domain\Ai\Clients\FakeLlmClient;
use App\Domain\Ai\LlmRequest;
use App\Domain\Ai\LlmRouter;
use App\Domain\Ai\PromptBudget;
use App\Domain\Applications\AnswerDraft;
use App\Domain\Applications\ApplicationQuestion;
use App\Domain\Applications\ApplicationQuestionService;
use App\Domain\Applications\JobAnalysis;
use App\Domain\Applications\JobApplication;
use App\Domain\Knowledge\WritingPreference;
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
    return ['answer' => $answer, 'claims' => $claims, 'clarification_questions' => $questions];
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

test('an AI version records what it was written from, unchanged by later edits of the library', function () {
    $candidate = candidateWithTwoFactor();
    $application = draftedApplicationOf($candidate);
    $claim = claimOf($candidate, 'Tuned PostgreSQL indexes and queries for the billing platform');
    $revisionId = $claim->current_revision_id;
    $analysis = inWorkspaceOf($candidate, fn () => tap((new JobAnalysis)->forceFill([
        'job_application_id' => $application->id,
        'job_posting_id' => $application->posting->id,
        'status' => 'succeeded',
        'brief' => ['summary' => 'Senior engineer'],
        'matches' => ['requirements' => [['kind' => 'must', 'evidence' => [['ref' => null, 'status' => 'verified', 'text' => 'Ran the on-call rota']]]]],
    ]))->save());
    $this->actingAs($candidate)->post(route('knowledge.preferences.store'), ['rule' => 'Use British spelling', 'kind' => 'spelling']);
    $question = questionToDraft($candidate, $application);
    app(LlmRouter::class)->fake(new FakeLlmClient([answerOutput('An answer.', [])]));
    $this->actingAs($candidate)->post(route('applications.questions.draft', [$application, $question]));

    $this->actingAs($candidate)->put(route('knowledge.claims.update', $claim), [
        'kind' => 'achievement',
        'statement' => 'Tuned PostgreSQL for the billing and payouts platforms',
        'change_reason' => 'Broader',
        'lock_version' => 0,
    ]);

    $version = draftOf($candidate, $question)->currentVersion;
    $searched = array_search('Tuned PostgreSQL indexes and queries for the billing platform', array_column($version->evidence_pack, 'text'), true);
    $searchedId = array_keys($version->evidence_pack)[$searched];
    expect($version->job_posting_id)->toBe($application->posting->id)
        ->and($version->job_analysis_id)->toBe($analysis->id)
        ->and($version->writing_rules)->toBe([['id' => $version->writing_rule_ids[0], 'line' => '(Spelling) Use British spelling']])
        ->and($version->evidence_pack[$searchedId]['text'])->toBe('Tuned PostgreSQL indexes and queries for the billing platform')
        ->and($version->retrieval['semantic'])->toBeTrue()
        ->and($version->retrieval['embedding_model']['provider'])->toBe('fake')
        ->and($version->retrieval['settings']['limit'])->toBe(6)
        // Only search hits that went into the pack have a source; the matched evidence has none.
        ->and(array_keys($version->retrieval['sources']))->toBe([$searchedId])
        ->and($version->retrieval['sources'][$searchedId]['source_type'])->toBe('claim')
        ->and($version->retrieval['sources'][$searchedId]['source_id'])->toBe($claim->id)
        ->and($version->retrieval['sources'][$searchedId]['source_revision_id'])->toBe($revisionId)
        ->and($claim->refresh()->current_revision_id)->not->toBe($revisionId);
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

test("a question's own evidence keeps its place when the job's requirements would fill the pack", function () {
    $candidate = candidateWithTwoFactor();
    $application = draftedApplicationOf($candidate);
    claimOf($candidate, 'Settled a schema conflict between two teams sharing one database');
    $requirements = array_map(fn (int $n): array => ['kind' => 'must', 'evidence' => [
        ['ref' => null, 'status' => 'verified', 'text' => "Requirement {$n} evidence A"],
        ['ref' => null, 'status' => 'verified', 'text' => "Requirement {$n} evidence B"],
    ]], range(1, 8));
    inWorkspaceOf($candidate, fn () => (new JobAnalysis)->forceFill([
        'job_application_id' => $application->id,
        'job_posting_id' => $application->posting->id,
        'status' => 'succeeded',
        'brief' => ['summary' => 'Senior engineer'],
        'matches' => ['requirements' => $requirements],
    ])->save());
    $question = questionToDraft($candidate, $application, 'Tell us about a time you settled a conflict.');
    $fake = app(LlmRouter::class)->fake(new FakeLlmClient([answerOutput('An answer.', [])]));

    $this->actingAs($candidate)->post(route('applications.questions.draft', [$application, $question]));

    $prompt = $fake->requests[0]->messages[0]['content'];
    expect(draftOf($candidate, $question)->currentVersion->evidence_pack)->toHaveCount(15)
        ->and($prompt)->toContain('<item id="E15" status="verified">Settled a schema conflict between two teams sharing one database</item>')
        ->and($prompt)->toContain('Requirement 7 evidence B')
        ->and($prompt)->not->toContain('Requirement 8 evidence A');
});

test("a job description too long for the model's window is shortened, and the evidence still goes in", function () {
    config(['ai.tasks.answer_writing.context' => 5000]);
    $candidate = candidateWithTwoFactor();
    $jobDescription = implode("\n", array_map(fn (int $n): string => "Line {$n} of a very long job description.", range(1, 300)));
    $application = JobApplication::factory()->forProfile(profileOf($candidate))->withJobDescription($jobDescription)->create(['company_name' => 'Fabrikam', 'role_title' => 'Senior Engineer']);
    claimOf($candidate, 'Tuned PostgreSQL indexes and queries for the billing platform');
    $question = questionToDraft($candidate, $application);
    $fake = app(LlmRouter::class)->fake(new FakeLlmClient([answerOutput('An answer.', [])]));

    $this->actingAs($candidate)->post(route('applications.questions.draft', [$application, $question]));

    $request = $fake->requests[0];
    $prompt = $request->messages[0]['content'];
    expect($prompt)->toContain('Line 1 of a very long job description.')
        ->and($prompt)->not->toContain('Line 300 of')
        ->and($prompt)->toContain("[… the rest of the job description was left out to fit the model's window]")
        ->and($prompt)->toContain('<item id="E1" status="verified">Tuned PostgreSQL indexes and queries for the billing platform</item>')
        ->and(mb_strlen($request->system) + mb_strlen($prompt))->toBeLessThanOrEqual((int) ((5000 - 2500) * (1 - PromptBudget::MARGIN)) * PromptBudget::CHARACTERS_PER_TOKEN);
});

test('when the window is small only the most important rules are sent, and only those are recorded', function () {
    config(['ai.tasks.answer_writing.context' => 4400]);
    $candidate = candidateWithTwoFactor();
    $application = draftedApplicationOf($candidate);

    foreach (range(1, 6) as $n) {
        $this->actingAs($candidate)->post(route('knowledge.preferences.store'), ['rule' => "Rule {$n}: ".str_repeat('keep this sentence plain and direct ', 5), 'kind' => 'tone']);
    }

    $question = questionToDraft($candidate, $application);
    $fake = app(LlmRouter::class)->fake(new FakeLlmClient([answerOutput('An answer.', [])]));

    $this->actingAs($candidate)->post(route('applications.questions.draft', [$application, $question]));

    $prompt = $fake->requests[0]->messages[0]['content'];
    $sent = inWorkspaceOf($candidate, fn () => draftOf($candidate, $question)->currentVersion->writing_rule_ids);
    $all = inWorkspaceOf($candidate, fn () => WritingPreference::query()->orderBy('id')->pluck('id')->all());
    expect($sent)->not->toBeEmpty()
        ->and(count($sent))->toBeLessThan(6)
        ->and($sent)->toBe(array_slice($all, 0, count($sent)))
        ->and($prompt)->toContain('Rule 1:')
        ->and($prompt)->not->toContain('Rule 6:');
});

test('a rule only for cover letters shapes cover letters, not answers to questions', function () {
    $candidate = candidateWithTwoFactor();
    $application = draftedApplicationOf($candidate);
    $this->actingAs($candidate)->post(route('knowledge.preferences.store'), ['rule' => 'Sign off with my first name', 'kind' => 'format', 'drafts' => 'cover_letter']);
    $this->actingAs($candidate)->post(route('knowledge.preferences.store'), ['rule' => 'Use British spelling', 'kind' => 'spelling']);
    $question = questionToDraft($candidate, $application);
    $this->actingAs($candidate)->post(route('applications.questions.cover-letter', $application));
    $letter = inWorkspaceOf($candidate, fn () => ApplicationQuestion::query()->where('origin', 'cover_letter')->sole());
    $fake = app(LlmRouter::class)->fake(new FakeLlmClient([answerOutput('An answer.', []), answerOutput('Dear hiring team, …', [])]));

    $this->actingAs($candidate)->post(route('applications.questions.draft', [$application, $question]));
    $this->actingAs($candidate)->post(route('applications.questions.draft', [$application, $letter]));

    expect($fake->requests[0]->messages[0]['content'])->toContain('- (Spelling) Use British spelling')
        ->and($fake->requests[0]->messages[0]['content'])->not->toContain('Sign off with my first name')
        ->and($fake->requests[1]->messages[0]['content'])->toContain('- (Format, cover letters only) Sign off with my first name')
        ->and($fake->requests[1]->messages[0]['content'])->toContain('- (Spelling) Use British spelling');
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

/**
 * A two-paragraph AI draft whose unconfirmed first-paragraph claim the candidate kept as worded.
 *
 * @return array{application: JobApplication, question: ApplicationQuestion, fake: FakeLlmClient}
 */
function twoParagraphDraft(User $candidate): array
{
    $application = draftedApplicationOf($candidate);
    claimOf($candidate, 'Tuned PostgreSQL indexes and queries for the billing platform');
    $question = questionToDraft($candidate, $application);
    $fake = app(LlmRouter::class)->fake(new FakeLlmClient([
        answerOutput("I tuned PostgreSQL for a billing platform, cutting latency by 60%.\n\nI would bring the same care to Fabrikam.", [
            ['text' => 'cutting latency by 60%', 'classification' => 'verified', 'evidence_ids' => ['E1']],
            ['text' => 'I would bring the same care', 'classification' => 'hypothetical', 'evidence_ids' => []],
        ], [['question' => 'By how much did latency drop?', 'why' => 'A number makes it concrete.']]),
    ]));
    test()->actingAs($candidate)->post(route('applications.questions.draft', [$application, $question]));
    test()->actingAs($candidate)
        ->post(route('applications.questions.reviews.accept', [$application, $question]), ['index' => 0])
        ->assertSessionHasNoErrors();

    return ['application' => $application, 'question' => $question, 'fake' => $fake];
}

describe('redrafting one paragraph', function () {
    test('only that paragraph changes, and what was checked in the others stays checked', function () {
        $candidate = candidateWithTwoFactor();
        ['application' => $application, 'question' => $question, 'fake' => $fake] = twoParagraphDraft($candidate);
        $fake->push([
            // Small models echo the tags they were shown; they are removed.
            'paragraph' => "<paragraph_to_rewrite>At Fabrikam I would start with the slowest queries (E1).\n\nThen the rest.</paragraph_to_rewrite>",
            'claims' => [['text' => 'I would start with the slowest queries', 'classification' => 'hypothetical', 'evidence_ids' => []]],
            'clarification_questions' => [],
        ]);

        $this->actingAs($candidate)
            ->post(route('applications.questions.draft', [$application, $question]), ['paragraph' => 1, 'instruction' => 'More concrete'])
            ->assertInertiaFlash('toast.message', 'Redrafting paragraph 2. It appears here when ready.');

        $draft = draftOf($candidate, $question);
        $version = $draft->currentVersion;
        $prompt = $fake->requests[1]->messages[0]['content'];
        expect($prompt)->toContain("<answer_before>\nI tuned PostgreSQL for a billing platform, cutting latency by 60%.\n</answer_before>")
            ->and($prompt)->toContain("<paragraph_to_rewrite>\nI would bring the same care to Fabrikam.\n</paragraph_to_rewrite>")
            ->and($prompt)->toContain('(this is the last paragraph)')
            ->and($prompt)->toContain('<item id="E1" status="verified">Tuned PostgreSQL indexes and queries for the billing platform</item>')
            ->and($draft->body())->toBe("I tuned PostgreSQL for a billing platform, cutting latency by 60%.\n\nAt Fabrikam I would start with the slowest queries. Then the rest.")
            ->and($version->paragraph)->toBe(1)
            ->and(array_column($version->grounding, 'text'))->toBe(['cutting latency by 60%', 'I would start with the slowest queries'])
            ->and($version->clarification_questions[0]['question'])->toBe('By how much did latency drop?')
            ->and($draft->needsClaimReview())->toBeFalse();

        $this->actingAs($candidate)
            ->get(route('applications.questions.index', $application))
            ->assertInertia(fn (Assert $page) => $page
                ->where('questions.0.history.0.change_reason', 'Redrafted paragraph 2 with AI: “More concrete”')
                ->where('questions.0.answer.ai.claims.0.review.decision', 'accepted'));
    });

    test('a redrafted paragraph is discarded when the answer is saved while it is written', function () {
        $candidate = candidateWithTwoFactor();
        ['application' => $application, 'question' => $question, 'fake' => $fake] = twoParagraphDraft($candidate);
        $fake->push(function () use ($candidate, $question): array {
            // Someone saves an edit while the model is still writing.
            inWorkspaceOf($candidate, fn () => app(ApplicationQuestionService::class)
                ->saveAnswer($question, 'My own rewrite.', null, draftOf($candidate, $question)->lock_version, $candidate));

            return ['paragraph' => 'Too late.', 'claims' => [], 'clarification_questions' => []];
        });

        $this->actingAs($candidate)->post(route('applications.questions.draft', [$application, $question]), ['paragraph' => 0]);

        $draft = draftOf($candidate, $question);
        expect($draft->body())->toBe('My own rewrite.')
            ->and($draft->generation_status)->toBe('failed')
            ->and($draft->generation_error)->toBe('The answer was saved or approved while the paragraph was being redrafted, so nothing was replaced. Try again.');
    });

    test('a paragraph that is not in the saved answer is refused, and one handed back unchanged is not stored', function () {
        $candidate = candidateWithTwoFactor();
        ['application' => $application, 'question' => $question, 'fake' => $fake] = twoParagraphDraft($candidate);

        $this->actingAs($candidate)
            ->post(route('applications.questions.draft', [$application, $question]), ['paragraph' => 2])
            ->assertSessionHasErrors(['paragraph' => 'That paragraph is not in the saved answer. Save your changes, then try again.']);

        $fake->push(['paragraph' => 'I would bring the same  care to Fabrikam.', 'claims' => [], 'clarification_questions' => []]);
        $this->actingAs($candidate)->post(route('applications.questions.draft', [$application, $question]), ['paragraph' => 1]);

        $draft = draftOf($candidate, $question);
        expect($draft->currentVersion->version_no)->toBe(1)
            ->and($draft->generation_error)->toBe('The AI gave the paragraph back unchanged. Try again with an instruction, or with a larger model.');
    });
});

test('draft all writes every question without an answer, the cover letter last', function () {
    $candidate = candidateWithTwoFactor();
    $application = draftedApplicationOf($candidate);
    $this->actingAs($candidate)->post(route('applications.questions.cover-letter', $application));
    $first = questionToDraft($candidate, $application, 'Why us?');
    $answered = questionToDraft($candidate, $application, 'Your notice period?');
    $this->actingAs($candidate)->put(route('applications.questions.answer', [$application, $answered]), ['body' => 'One month.', 'lock_version' => 0]);
    $fake = app(LlmRouter::class)->fake(new FakeLlmClient([
        answerOutput('Because of the product.', []),
        answerOutput('Dear hiring team, …', []),
    ]));

    $this->actingAs($candidate)
        ->post(route('applications.questions.draft-all', $application))
        ->assertInertiaFlash('toast.message', 'Writing 2 drafts, one after another. Each appears here when ready.');

    expect($fake->requests)->toHaveCount(2)
        ->and($fake->requests[0]->messages[0]['content'])->toContain('Question: Why us?')
        ->and($fake->requests[1]->messages[0]['content'])->toContain('Write a cover letter')
        ->and(draftOf($candidate, $first)->body())->toBe('Because of the product.')
        ->and(draftOf($candidate, $answered)->body())->toBe('One month.');

    $this->actingAs($candidate)
        ->post(route('applications.questions.draft-all', $application))
        ->assertInertiaFlash('toast.message', 'Every question already has an answer or a draft on the way.');
});

describe('claims labelled by a separate check', function () {
    beforeEach(fn () => config(['ai.answer_claims' => 'separate']));

    test('the writer writes only the text, and the claim check labels its claims afterwards', function () {
        $candidate = candidateWithTwoFactor();
        $application = draftedApplicationOf($candidate);
        claimOf($candidate, 'Tuned PostgreSQL indexes and queries for the billing platform');
        $question = questionToDraft($candidate, $application);
        $fake = app(LlmRouter::class)->fake(new FakeLlmClient([
            ['answer' => 'I tuned PostgreSQL for a billing platform, cutting latency by 60%.', 'clarification_questions' => []],
            ['claims' => [
                ['text' => 'I tuned PostgreSQL for a billing platform', 'classification' => 'verified', 'evidence_ids' => ['E1'], 'issue' => ''],
                ['text' => 'cutting latency by 60%', 'classification' => 'verified', 'evidence_ids' => ['E1'], 'issue' => 'number not in evidence'],
            ]],
        ]));

        $this->actingAs($candidate)->post(route('applications.questions.draft', [$application, $question]));

        $draft = draftOf($candidate, $question);
        expect($fake->requests[0]->schema['required'])->toBe(['answer', 'clarification_questions'])
            ->and($fake->requests[1]->system)->toStartWith('You check a draft')
            ->and($fake->requests[1]->messages[0]['content'])->toContain('I tuned PostgreSQL for a billing platform, cutting latency by 60%.')
            ->and($draft->generation_status)->toBeNull()
            ->and(array_column($draft->currentVersion->grounding, 'classification'))->toBe(['verified', 'proposed'])
            ->and($draft->needsClaimReview())->toBeTrue();
    });

    test('an unlabelled draft cannot be approved until someone checks it as a whole', function () {
        $candidate = candidateWithTwoFactor();
        $application = draftedApplicationOf($candidate);
        $question = questionToDraft($candidate, $application);
        // The claim check answers with unusable output twice (with its repair attempt).
        app(LlmRouter::class)->fake(new FakeLlmClient([
            ['answer' => 'I would bring calm to Fabrikam.', 'clarification_questions' => []],
            'not json', 'still not json',
        ]));

        $this->actingAs($candidate)->post(route('applications.questions.draft', [$application, $question]));

        $draft = draftOf($candidate, $question);
        expect($draft->currentVersion->grounding)->toBeNull()
            ->and($draft->generation_status)->toBe('failed')
            ->and($draft->generation_error)->toContain('could not label');
        $this->actingAs($candidate)
            ->patch(route('applications.questions.approve', [$application, $question]))
            ->assertSessionHasErrors('body');

        $this->actingAs($candidate)->patch(route('applications.questions.claims-reviewed', [$application, $question]));
        $this->actingAs($candidate)
            ->patch(route('applications.questions.approve', [$application, $question]))
            ->assertSessionHasNoErrors();
    });
});
