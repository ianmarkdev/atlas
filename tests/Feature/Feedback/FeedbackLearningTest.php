<?php

use App\Domain\Ai\Clients\FakeLlmClient;
use App\Domain\Ai\LlmRouter;
use App\Domain\Applications\ApplicationQuestion;
use App\Domain\Applications\JobApplication;
use App\Domain\Feedback\FeedbackEvent;
use App\Domain\Knowledge\AnswerLibraryEntry;
use App\Domain\Knowledge\AnswerStatus;
use App\Domain\Knowledge\Claim;
use App\Domain\Knowledge\ClaimService;
use App\Domain\Knowledge\ClaimStatus;
use App\Domain\Knowledge\KnowledgeProposal;
use App\Domain\Knowledge\ProposalStatus;
use App\Domain\Knowledge\WritingPreference;
use App\Domain\Retrieval\RetrievalUnit;
use App\Models\User;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;

const FB_BULLET = 'Tuned PostgreSQL queries for the billing platform';
const FB_AI_TEXT = 'I tuned PostgreSQL queries for the billing platform, and I am passionate about databases.';
const FB_EDITED = 'I tuned PostgreSQL queries for the billing platform, cutting p95 latency by 40%. I optimise databases for a living.';

/**
 * @param  list<array<string, mixed>>  $items
 * @return array{items: list<array<string, mixed>>}
 */
function fbAnalysis(array $items): array
{
    $defaults = ['evidence_id' => null, 'statement' => null, 'rule' => null, 'rule_kind' => null, 'personal' => null, 'same_as' => null, 'conflicts_with' => null, 'drafts' => null, 'category' => null, 'confidence' => 0.9, 'rationale' => 'The edit shows it.'];

    return ['items' => array_map(fn (array $item): array => [...$defaults, ...$item], $items)];
}

/**
 * The AI drafts an answer citing the candidate's PostgreSQL bullet point as E1, then `$editor`
 * rewrites it. Later AI calls answer with `$responses`, in order.
 *
 * @param  list<array<string, mixed>>  $responses
 * @return array{application: JobApplication, question: ApplicationQuestion, claim: Claim, fake: FakeLlmClient}
 */
function fbEditedDraft(User $candidate, User $editor, array $responses, string $prompt = 'Tell us about your PostgreSQL work.', ?Claim $claim = null): array
{
    $claim ??= claimOf($candidate, FB_BULLET);
    $application = JobApplication::factory()->forProfile(profileOf($candidate))->create(['company_name' => 'Fabrikam', 'role_title' => 'Senior Engineer']);
    test()->actingAs($candidate)->post(route('applications.questions.store', $application), ['prompt_text' => $prompt]);
    $question = inWorkspaceOf($candidate, fn () => ApplicationQuestion::query()->where('job_application_id', $application->id)->sole());

    $fake = app(LlmRouter::class)->fake(new FakeLlmClient([
        ['answer' => FB_AI_TEXT, 'claims' => [['text' => 'I tuned PostgreSQL queries for the billing platform', 'classification' => 'verified', 'evidence_ids' => ['E1']]], 'clarification_questions' => []],
        ...$responses,
    ]));
    test()->actingAs($candidate)->post(route('applications.questions.draft', [$application, $question]));
    test()->actingAs($editor)
        ->put(route('applications.questions.answer', [$application, $question]), ['body' => FB_EDITED, 'lock_version' => 1])
        ->assertSessionHasNoErrors();

    return ['application' => $application, 'question' => $question, 'claim' => $claim, 'fake' => $fake];
}

function fbApprove(User $candidate, JobApplication $application, ApplicationQuestion $question): void
{
    test()->actingAs($candidate)
        ->patch(route('applications.questions.approve', [$application, $question]))
        ->assertSessionHasNoErrors();
}

/**
 * @return Collection<int, KnowledgeProposal>
 */
function fbProposals(User $candidate): mixed
{
    return inWorkspaceOf($candidate, fn () => KnowledgeProposal::query()->where('origin', 'feedback')->orderBy('id')->get());
}

test('approving an edited AI draft turns its lessons into proposals; a one-off creates none', function () {
    $candidate = candidateWithTwoFactor();
    ['application' => $application, 'question' => $question, 'claim' => $claim, 'fake' => $fake] = fbEditedDraft($candidate, $candidate, [fbAnalysis([
        ['type' => 'factual_correction', 'evidence_id' => 'E1', 'statement' => 'Tuned PostgreSQL queries for the billing platform, cutting p95 latency by 40%'],
        ['type' => 'writing_preference', 'rule' => 'Never use the word passionate', 'rule_kind' => 'banned_phrase', 'personal' => false],
        ['type' => 'reusable_answer', 'category' => 'technical'],
        ['type' => 'one_off'],
    ])]);

    fbApprove($candidate, $application, $question);

    $event = inWorkspaceOf($candidate, fn () => FeedbackEvent::query()->sole());
    $proposals = fbProposals($candidate);
    $prompt = $fake->requests[1]->messages[0]['content'];
    expect($event->trigger)->toBe('approval')
        ->and($event->status)->toBe('analysed')
        ->and(collect($event->diff)->where('op', 'insert')->pluck('text')->implode(' '))->toContain('40%')
        ->and(array_column($event->analysis['items'], 'outcome'))->toBe(['proposed', 'proposed', 'proposed', 'recorded'])
        ->and($prompt)->toContain('+ cutting p95 latency by 40%')
        ->and($prompt)->toContain('<item id="E1" status="verified">'.FB_BULLET.'</item>')
        ->and($proposals->pluck('proposal_type')->all())->toBe(['revise_claim', 'create_writing_preference', 'create_library_answer'])
        ->and($proposals->every(fn (KnowledgeProposal $p): bool => $p->origin_id === $event->id && $p->status === ProposalStatus::Pending))->toBeTrue()
        ->and($proposals[0]->target_id)->toBe($claim->id)
        ->and($proposals[2]->payload)->toEqual(['question' => 'Tell us about your PostgreSQL work.', 'body' => FB_EDITED, 'category' => 'technical']);

    $this->actingAs($candidate)
        ->get(route('applications.questions.index', $application))
        ->assertInertia(fn (Assert $page) => $page
            ->where('questions.0.answer.feedback', ['status' => 'analysed', 'error' => null, 'suggestions' => 3, 'pending' => 3])
            ->where('questions.0.can.learn', false));
});

test('a correction with a number the person did not write is not proposed', function () {
    $candidate = candidateWithTwoFactor();
    ['application' => $application, 'question' => $question] = fbEditedDraft($candidate, $candidate, [fbAnalysis([
        ['type' => 'factual_correction', 'evidence_id' => 'E1', 'statement' => 'Cut p95 latency by 60% on the billing platform'],
    ])]);

    fbApprove($candidate, $application, $question);

    expect(fbProposals($candidate))->toBeEmpty()
        ->and(inWorkspaceOf($candidate, fn () => FeedbackEvent::query()->sole())->analysis['items'][0]['reason'])->toBe('the number 60 is not in the edited text');
});

test('a writing rule the AI is unsure of waits until a second edit shows it', function () {
    $candidate = candidateWithTwoFactor();
    $unsure = fbAnalysis([['type' => 'writing_preference', 'rule' => 'Use British spelling', 'rule_kind' => 'spelling', 'personal' => false, 'confidence' => 0.5]]);
    $first = fbEditedDraft($candidate, $candidate, [$unsure]);
    fbApprove($candidate, $first['application'], $first['question']);

    expect(fbProposals($candidate))->toBeEmpty();

    $second = fbEditedDraft($candidate, $candidate, [$unsure], claim: $first['claim']);
    fbApprove($candidate, $second['application'], $second['question']);

    [$firstEvent, $secondEvent] = inWorkspaceOf($candidate, fn () => FeedbackEvent::query()->orderBy('id')->pluck('id')->all());
    expect(fbProposals($candidate)->sole()->payload)->toEqual(['rule' => 'Use British spelling', 'kind' => 'spelling', 'scope' => 'profile', 'drafts' => 'all', 'supported_by' => [$secondEvent, $firstEvent]]);
});

test('a held rule is proposed when a later edit repeats it in other words, and is not offered again', function () {
    $candidate = candidateWithTwoFactor();
    $first = fbEditedDraft($candidate, $candidate, [fbAnalysis([['type' => 'writing_preference', 'rule' => 'Use British spelling', 'rule_kind' => 'spelling', 'personal' => false, 'confidence' => 0.5]])]);
    fbApprove($candidate, $first['application'], $first['question']);
    $heldId = 'H'.inWorkspaceOf($candidate, fn () => FeedbackEvent::query()->sole())->id.'.0';

    $second = fbEditedDraft($candidate, $candidate, [fbAnalysis([['type' => 'writing_preference', 'rule' => 'Spell words the UK way', 'rule_kind' => 'spelling', 'personal' => false, 'same_as' => $heldId, 'confidence' => 0.5]])], claim: $first['claim']);
    fbApprove($candidate, $second['application'], $second['question']);

    [$firstEvent, $secondEvent] = inWorkspaceOf($candidate, fn () => FeedbackEvent::query()->orderBy('id')->pluck('id')->all());
    expect($second['fake']->requests[1]->messages[0]['content'])->toContain("<rule id=\"{$heldId}\">Use British spelling</rule>")
        ->and(fbProposals($candidate)->sole()->payload)->toEqual(['rule' => 'Spell words the UK way', 'kind' => 'spelling', 'scope' => 'profile', 'drafts' => 'all', 'supported_by' => [$secondEvent, $firstEvent]]);

    $third = fbEditedDraft($candidate, $candidate, [fbAnalysis([['type' => 'one_off']])], claim: $first['claim']);
    fbApprove($candidate, $third['application'], $third['question']);

    expect($third['fake']->requests[1]->messages[0]['content'])->toContain("<held_rules>\n(none)\n</held_rules>");
});

test('an edit that applies a rule the draft was written with is kept as a miss of that rule, not suggested again', function () {
    $candidate = candidateWithTwoFactor();
    $this->actingAs($candidate)->post(route('knowledge.preferences.store'), ['rule' => 'Never use the word passionate', 'kind' => 'banned_phrase']);
    $rule = inWorkspaceOf($candidate, fn () => WritingPreference::query()->sole());
    ['application' => $application, 'question' => $question, 'fake' => $fake] = fbEditedDraft($candidate, $candidate, [fbAnalysis([
        ['type' => 'writing_preference', 'rule' => 'Do not call yourself passionate', 'rule_kind' => 'banned_phrase', 'personal' => false, 'same_as' => "R{$rule->id}"],
    ])]);

    fbApprove($candidate, $application, $question);

    $event = inWorkspaceOf($candidate, fn () => FeedbackEvent::query()->with('fromVersion')->sole());
    expect($event->fromVersion->writing_rule_ids)->toBe([$rule->id])
        ->and($fake->requests[1]->messages[0]['content'])->toContain("<rule id=\"R{$rule->id}\">(Words to avoid) Never use the word passionate</rule>")
        ->and($event->analysis['items'][0]['outcome'])->toBe('missed')
        ->and(fbProposals($candidate))->toBeEmpty()
        ->and(inWorkspaceOf($candidate, fn () => $rule->missedIn()->pluck('feedback_events.id')->all()))->toBe([$event->id]);

    $this->actingAs($candidate)
        ->get(route('knowledge.preferences.index'))
        ->assertInertia(fn (Assert $page) => $page->where('shared.0.missed_in', 1));
});

test('a rule added after the draft was written is not counted as missed, nor suggested again, but counts the edit as one it was seen in', function () {
    $candidate = candidateWithTwoFactor();
    $ruleId = null;
    ['application' => $application, 'question' => $question] = fbEditedDraft($candidate, $candidate, [
        function () use (&$ruleId): array {
            return fbAnalysis([['type' => 'writing_preference', 'rule' => 'Do not call yourself passionate', 'rule_kind' => 'banned_phrase', 'personal' => false, 'same_as' => "R{$ruleId}"]]);
        },
    ]);
    $this->actingAs($candidate)->post(route('knowledge.preferences.store'), ['rule' => 'Never use the word passionate', 'kind' => 'banned_phrase']);
    $ruleId = inWorkspaceOf($candidate, fn () => WritingPreference::query()->sole())->id;

    fbApprove($candidate, $application, $question);

    $event = inWorkspaceOf($candidate, fn () => FeedbackEvent::query()->sole());
    $rule = inWorkspaceOf($candidate, fn () => WritingPreference::query()->sole());
    expect($event->analysis['items'][0]['outcome'])->toBe('supported')
        ->and(fbProposals($candidate))->toBeEmpty()
        ->and(inWorkspaceOf($candidate, fn () => $rule->missedIn()->count()))->toBe(0)
        ->and(inWorkspaceOf($candidate, fn () => $rule->supportingEdits()->pluck('feedback_events.id')->all()))->toBe([$event->id]);
});

test('a rule only like a held one stays apart from it: both wait for their own second sighting', function () {
    $candidate = candidateWithTwoFactor();
    $first = fbEditedDraft($candidate, $candidate, [fbAnalysis([['type' => 'writing_preference', 'rule' => 'Use British spelling', 'rule_kind' => 'spelling', 'personal' => false, 'confidence' => 0.5]])]);
    fbApprove($candidate, $first['application'], $first['question']);

    $second = fbEditedDraft($candidate, $candidate, [fbAnalysis([['type' => 'writing_preference', 'rule' => 'Write in a British tone', 'rule_kind' => 'tone', 'personal' => false, 'confidence' => 0.5]])], claim: $first['claim']);
    fbApprove($candidate, $second['application'], $second['question']);
    $third = fbEditedDraft($candidate, $candidate, [fbAnalysis([['type' => 'one_off']])], claim: $first['claim']);
    fbApprove($candidate, $third['application'], $third['question']);

    [$firstEvent, $secondEvent] = inWorkspaceOf($candidate, fn () => FeedbackEvent::query()->orderBy('id')->limit(2)->pluck('id')->all());
    expect(fbProposals($candidate))->toBeEmpty()
        ->and($third['fake']->requests[1]->messages[0]['content'])->toContain("<rule id=\"H{$secondEvent}.0\">Write in a British tone</rule>\n<rule id=\"H{$firstEvent}.0\">Use British spelling</rule>");
});

test('a learned rule that contradicts one in place is shown beside it, and replaces it only if the reviewer says so', function () {
    $candidate = candidateWithTwoFactor();
    $this->actingAs($candidate)->post(route('knowledge.preferences.store'), ['rule' => 'Keep answers short', 'kind' => 'format']);
    $old = inWorkspaceOf($candidate, fn () => WritingPreference::query()->sole());
    ['application' => $application, 'question' => $question] = fbEditedDraft($candidate, $candidate, [fbAnalysis([
        ['type' => 'writing_preference', 'rule' => 'Give a detailed example in every answer', 'rule_kind' => 'structure', 'personal' => false, 'conflicts_with' => "R{$old->id}"],
    ])]);
    fbApprove($candidate, $application, $question);
    $proposal = fbProposals($candidate)->sole();

    expect($proposal->payload['replaces'])->toBe($old->id);
    // An edit the old rule was learned from carries over to the rule replacing it.
    $other = fbEditedDraft($candidate, $candidate, [fbAnalysis([['type' => 'one_off']])], claim: inWorkspaceOf($candidate, fn () => Claim::query()->sole()));
    fbApprove($candidate, $other['application'], $other['question']);
    $events = inWorkspaceOf($candidate, fn () => FeedbackEvent::query()->orderBy('id')->pluck('id')->all());
    inWorkspaceOf($candidate, fn () => $old->supportingEdits()->attach($events[1], ['workspace_id' => $old->workspace_id]));
    $this->actingAs($candidate)
        ->get(route('knowledge.proposals.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('pending.0.related_rules.0.ulid', $old->ulid)
            ->where('pending.0.related_rules.0.suggested', true)
            ->where('pending.0.related_rules.0.can_replace', true));

    $this->actingAs($candidate)
        ->patch(route('knowledge.proposals.approve', $proposal), ['replaces' => $old->ulid])
        ->assertSessionHasNoErrors();

    expect(inWorkspaceOf($candidate, fn () => WritingPreference::query()->orderBy('id')->get()->map(fn (WritingPreference $rule): array => [$rule->rule, $rule->status])->all()))
        ->toBe([['Keep answers short', 'archived'], ['Give a detailed example in every answer', 'active']])
        ->and(inWorkspaceOf($candidate, fn () => WritingPreference::query()->where('status', 'active')->sole()->supportingEdits()->orderBy('feedback_events.id')->pluck('feedback_events.id')->all()))->toBe($events);
});

test('a reviewer cannot replace a rule they do not manage', function () {
    $candidate = candidateWithTwoFactor();
    $va = vaOf($candidate);
    $this->actingAs($candidate)->post(route('knowledge.preferences.store'), ['rule' => 'Keep answers short', 'kind' => 'format']);
    $shared = inWorkspaceOf($candidate, fn () => WritingPreference::query()->sole());
    ['application' => $application, 'question' => $question] = fbEditedDraft($candidate, $va, [fbAnalysis([
        ['type' => 'writing_preference', 'rule' => 'Keep answers very short', 'rule_kind' => 'format', 'personal' => true],
    ])]);
    $this->actingAs($va)->post(route('applications.questions.learn', [$application, $question]));
    $proposal = fbProposals($candidate)->sole();

    $this->actingAs($va)
        ->get(route('knowledge.proposals.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('pending.0.related_rules.0.ulid', $shared->ulid)
            ->where('pending.0.related_rules.0.can_replace', false));

    $this->actingAs($va)
        ->patch(route('knowledge.proposals.approve', $proposal), ['replaces' => $shared->ulid])
        ->assertSessionHasErrors(['replaces' => 'You cannot replace that rule.']);

    expect(inWorkspaceOf($candidate, fn () => $proposal->fresh()->status))->toBe(ProposalStatus::Pending)
        ->and(inWorkspaceOf($candidate, fn () => $shared->fresh()->status))->toBe('active');
});

test('a held rule is not promoted by an id that names no held rule', function () {
    $candidate = candidateWithTwoFactor();
    $first = fbEditedDraft($candidate, $candidate, [fbAnalysis([['type' => 'writing_preference', 'rule' => 'Use British spelling', 'rule_kind' => 'spelling', 'personal' => false, 'confidence' => 0.5]])]);
    fbApprove($candidate, $first['application'], $first['question']);

    $second = fbEditedDraft($candidate, $candidate, [fbAnalysis([['type' => 'writing_preference', 'rule' => 'Write in a British tone', 'rule_kind' => 'tone', 'personal' => false, 'same_as' => 'H999.0', 'confidence' => 0.5]])], claim: $first['claim']);
    fbApprove($candidate, $second['application'], $second['question']);

    expect(fbProposals($candidate))->toBeEmpty()
        ->and(inWorkspaceOf($candidate, fn () => FeedbackEvent::query()->latest('id')->first())->analysis['items'][0]['outcome'])->toBe('held');
});

test('a learned rule is narrowed to one kind of draft only when its edits were of that kind', function () {
    $candidate = candidateWithTwoFactor();
    ['application' => $application, 'question' => $question] = fbEditedDraft($candidate, $candidate, [fbAnalysis([
        ['type' => 'writing_preference', 'rule' => 'Keep answers under 150 words', 'rule_kind' => 'format', 'personal' => false, 'drafts' => 'question'],
        ['type' => 'writing_preference', 'rule' => 'Open with Dear hiring team', 'rule_kind' => 'structure', 'personal' => false, 'drafts' => 'cover_letter'],
    ])]);

    fbApprove($candidate, $application, $question);

    expect(fbProposals($candidate)->pluck('payload.drafts')->all())->toBe(['question', 'all']);
});

test('approving a learned rule keeps the edits it was seen in, with the drafts the reviewer chose', function () {
    $candidate = candidateWithTwoFactor();
    $unsure = fbAnalysis([['type' => 'writing_preference', 'rule' => 'Use British spelling', 'rule_kind' => 'spelling', 'personal' => false, 'confidence' => 0.5]]);
    $first = fbEditedDraft($candidate, $candidate, [$unsure]);
    fbApprove($candidate, $first['application'], $first['question']);
    $second = fbEditedDraft($candidate, $candidate, [$unsure], claim: $first['claim']);
    fbApprove($candidate, $second['application'], $second['question']);

    $this->actingAs($candidate)
        ->patch(route('knowledge.proposals.approve', fbProposals($candidate)->sole()), ['drafts' => 'question'])
        ->assertSessionHasNoErrors();

    $rule = inWorkspaceOf($candidate, fn () => WritingPreference::query()->with('supportingEdits')->sole());
    expect($rule->drafts->value)->toBe('question')
        ->and($rule->supportingEdits->pluck('id')->sort()->values()->all())->toBe(inWorkspaceOf($candidate, fn () => FeedbackEvent::query()->orderBy('id')->pluck('id')->all()));

    $this->actingAs($candidate)
        ->get(route('knowledge.preferences.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('shared.0.seen_in', 2)
            ->where('shared.0.drafts_label', 'Question answers only'));
});

test('nothing is learned from an AI draft approved as written', function () {
    $candidate = candidateWithTwoFactor();
    claimOf($candidate, FB_BULLET);
    $application = JobApplication::factory()->forProfile(profileOf($candidate))->create();
    $this->actingAs($candidate)->post(route('applications.questions.store', $application), ['prompt_text' => 'Why us?']);
    $question = inWorkspaceOf($candidate, fn () => ApplicationQuestion::query()->sole());
    // Only the draft is queued: a feedback analysis would find no response and fail the test.
    app(LlmRouter::class)->fake(new FakeLlmClient([
        ['answer' => 'I like Fabrikam.', 'claims' => [['text' => 'I like Fabrikam', 'classification' => 'hypothetical', 'evidence_ids' => []]], 'clarification_questions' => []],
    ]));
    $this->actingAs($candidate)->post(route('applications.questions.draft', [$application, $question]));

    $this->actingAs($candidate)
        ->post(route('applications.questions.learn', [$application, $question]))
        ->assertSessionHasErrors(['feedback' => 'There are no new edits of an AI draft to learn from.']);
    fbApprove($candidate, $application, $question);

    expect(inWorkspaceOf($candidate, fn () => FeedbackEvent::query()->count()))->toBe(0);
});

test('learning can be asked for before approving, and each edit is learned from once', function () {
    $candidate = candidateWithTwoFactor();
    ['application' => $application, 'question' => $question] = fbEditedDraft($candidate, $candidate, [fbAnalysis([['type' => 'one_off']])]);

    $this->actingAs($candidate)
        ->get(route('applications.questions.index', $application))
        ->assertInertia(fn (Assert $page) => $page->where('questions.0.can.learn', true));

    $this->actingAs($candidate)
        ->post(route('applications.questions.learn', [$application, $question]))
        ->assertInertiaFlash('toast.message', 'Learning from your edits. Suggestions appear under Suggestions for approval.');
    fbApprove($candidate, $application, $question);

    expect(inWorkspaceOf($candidate, fn () => FeedbackEvent::query()->sole())->trigger)->toBe('manual');
});

test("a VA's personal writing habit is theirs to approve, while facts stay the candidate's", function () {
    $candidate = candidateWithTwoFactor();
    $va = vaOf($candidate);
    ['application' => $application, 'question' => $question] = fbEditedDraft($candidate, $va, [fbAnalysis([
        ['type' => 'writing_preference', 'rule' => 'Open with the result', 'rule_kind' => 'structure', 'personal' => true],
        ['type' => 'factual_correction', 'evidence_id' => 'E1', 'statement' => 'Cut p95 latency of the billing platform by 40%'],
    ])]);
    $this->actingAs($va)->post(route('applications.questions.learn', [$application, $question]));
    [$personal, $correction] = fbProposals($candidate)->all();

    expect($personal->reviewer_user_id)->toBe($va->id)
        ->and($correction->reviewer_user_id)->toBeNull();

    $this->actingAs($candidate)
        ->get(route('knowledge.proposals.index'))
        ->assertInertia(fn (Assert $page) => $page->where('pendingProposals', 1)->where('pending.0.type', 'revise_claim'));
    $this->actingAs($va)
        ->get(route('knowledge.proposals.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('pendingProposals', 1)
            ->where('pending.0.type', 'create_writing_preference')
            ->where('pending.0.can.decide', true)
            ->where('pending.0.learned_from.question', 'Tell us about your PostgreSQL work.'));

    $this->actingAs($candidate)->patch(route('knowledge.proposals.approve', $personal))->assertForbidden();
    $this->actingAs($va)->patch(route('knowledge.proposals.approve', $correction))->assertForbidden();
    $this->actingAs($va)->patch(route('knowledge.proposals.approve', $personal))->assertSessionHasNoErrors();

    $rule = inWorkspaceOf($candidate, fn () => WritingPreference::query()->sole());
    expect($rule->scope)->toBe('user')
        ->and($rule->user_id)->toBe($va->id)
        ->and($rule->created_via)->toBe("proposal:{$personal->id}");
});

test('approved lessons become new revisions, are found by the next search and shape the next draft', function () {
    $candidate = candidateWithTwoFactor();
    ['application' => $application, 'question' => $question, 'claim' => $claim] = fbEditedDraft($candidate, $candidate, [fbAnalysis([
        ['type' => 'factual_correction', 'evidence_id' => 'E1', 'statement' => 'Tuned PostgreSQL queries for the billing platform, cutting p95 latency by 40%'],
        ['type' => 'writing_preference', 'rule' => 'Use British spelling', 'rule_kind' => 'spelling', 'personal' => false],
        ['type' => 'reusable_answer', 'category' => 'technical'],
    ])]);
    fbApprove($candidate, $application, $question);
    [$correction, $rule, $answer] = fbProposals($candidate)->all();

    // Edit-then-approve: the candidate's own wording is what lands.
    $this->actingAs($candidate)->patch(route('knowledge.proposals.approve', $correction), ['statement' => 'Tuned PostgreSQL queries for the billing platform, cutting p95 latency by 40 percent']);
    $this->actingAs($candidate)->patch(route('knowledge.proposals.approve', $rule));
    $this->actingAs($candidate)->patch(route('knowledge.proposals.approve', $answer), ['body' => 'I tune PostgreSQL for a living.']);

    $claim = inWorkspaceOf($candidate, fn () => $claim->fresh(['currentRevision', 'revisions']));
    $unit = inWorkspaceOf($candidate, fn () => RetrievalUnit::query()->where('source_type', 'claim')->where('source_id', $claim->id)->sole());
    $entry = inWorkspaceOf($candidate, fn () => AnswerLibraryEntry::query()->with('currentRevision')->sole());
    expect($claim->statement())->toBe('Tuned PostgreSQL queries for the billing platform, cutting p95 latency by 40 percent')
        ->and($claim->status)->toBe(ClaimStatus::Verified)
        ->and($claim->revisions->pluck('statement')->all())->toBe([FB_BULLET, $claim->statement()])
        ->and($unit->search_text)->toContain('40 percent')
        ->and($entry->status)->toBe(AnswerStatus::Approved)
        ->and($entry->body())->toBe('I tune PostgreSQL for a living.');

    $this->actingAs($candidate)->post(route('applications.questions.store', $application), ['prompt_text' => 'Another question?']);
    $next = inWorkspaceOf($candidate, fn () => ApplicationQuestion::query()->where('prompt_text', 'Another question?')->sole());
    $fake = app(LlmRouter::class)->fake(new FakeLlmClient([['answer' => 'Fine.', 'claims' => [], 'clarification_questions' => []]]));
    $this->actingAs($candidate)->post(route('applications.questions.draft', [$application, $next]));

    expect($fake->requests[0]->messages[0]['content'])->toContain("<writing_rules>\n- (Spelling) Use British spelling\n</writing_rules>");
});

test('a correction of a bullet point changed since is not applied, and the reviewer sees the wording it was based on', function () {
    $candidate = candidateWithTwoFactor();
    ['application' => $application, 'question' => $question, 'claim' => $claim] = fbEditedDraft($candidate, $candidate, [fbAnalysis([
        ['type' => 'factual_correction', 'evidence_id' => 'E1', 'statement' => 'Tuned PostgreSQL queries for the billing platform, cutting p95 latency by 40%'],
    ])]);
    fbApprove($candidate, $application, $question);
    inWorkspaceOf($candidate, function () use ($claim, $candidate): void {
        $claim = $claim->fresh(['currentRevision', 'subject']);
        app(ClaimService::class)->revise($claim, 'Tuned PostgreSQL and Redis for the billing platform', $claim->kind, null, $claim->lock_version, $candidate);
    });
    $proposal = fbProposals($candidate)->sole();

    $this->actingAs($candidate)
        ->get(route('knowledge.proposals.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('pending.0.changes.0.from', 'Tuned PostgreSQL and Redis for the billing platform')
            ->where('pending.0.changes.0.base', FB_BULLET));

    $this->actingAs($candidate)
        ->patch(route('knowledge.proposals.approve', $proposal))
        ->assertInertiaFlash('toast.type', 'error');

    expect(fbProposals($candidate)->sole()->status)->toBe(ProposalStatus::Conflict)
        ->and(inWorkspaceOf($candidate, fn () => $claim->fresh('currentRevision'))->statement())->toBe('Tuned PostgreSQL and Redis for the billing platform');
});
