<?php

use App\Domain\Ai\Clients\FakeLlmClient;
use App\Domain\Ai\LlmRouter;
use App\Domain\Applications\AnswerDraft;
use App\Domain\Applications\ApplicationQuestion;
use App\Domain\Applications\JobApplication;
use App\Domain\Knowledge\Claim;
use App\Domain\Knowledge\ClaimStatus;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * An application question with an AI draft holding two unconfirmed claims (indexes 0 and 1) and
 * one AI question.
 *
 * @return array{candidate: User, application: JobApplication, question: ApplicationQuestion}
 */
function reviewedDraftSetup(): array
{
    $candidate = candidateWithTwoFactor();
    $application = JobApplication::factory()->forProfile(profileOf($candidate))->withJobDescription('Payments engineer.')->create();
    test()->actingAs($candidate)->post(route('applications.questions.store', $application), ['prompt_text' => 'Why us?']);
    $question = inWorkspaceOf($candidate, fn () => ApplicationQuestion::query()->sole());
    app(LlmRouter::class)->fake(new FakeLlmClient([[
        'answer' => 'I led a payments migration. I would bring calm to incidents.',
        'segments' => [['text' => 'I led a payments migration. I would bring calm to incidents.', 'claims' => [
            ['text' => 'I led a payments migration', 'classification' => 'proposed', 'evidence_ids' => []],
            ['text' => 'I kept incidents calm', 'classification' => 'verified', 'evidence_ids' => []],
            ['text' => 'I would bring calm to incidents', 'classification' => 'hypothetical', 'evidence_ids' => []],
        ]]],
        'clarification_questions' => [['question' => 'How many merchants moved?', 'why' => 'Scale matters.']],
    ]]));
    test()->actingAs($candidate)->post(route('applications.questions.draft', [$application, $question]));

    return compact('candidate', 'application', 'question');
}

function reviewedDraftOf(User $candidate): AnswerDraft
{
    return inWorkspaceOf($candidate, fn () => AnswerDraft::query()->with('currentVersion.reviews.claim')->sole());
}

test('each unconfirmed claim is settled one by one before the answer can be approved', function () {
    ['candidate' => $candidate, 'application' => $application, 'question' => $question] = reviewedDraftSetup();
    $routeArgs = [$application, $question];

    expect(reviewedDraftOf($candidate)->needsClaimReview())->toBeTrue();

    $this->actingAs($candidate)->post(route('applications.questions.reviews.accept', $routeArgs), ['index' => 0])->assertSessionHasNoErrors();
    expect(reviewedDraftOf($candidate)->needsClaimReview())->toBeTrue();

    $this->actingAs($candidate)
        ->post(route('applications.questions.reviews.library', $routeArgs), ['item_type' => 'claim', 'index' => 1, 'statement' => 'Kept payment incidents calm as on-call lead', 'subject' => 'general'])
        ->assertInertiaFlash('toast.message', 'Added to your library as a verified bullet point.');

    $draft = reviewedDraftOf($candidate);
    expect($draft->needsClaimReview())->toBeFalse()
        ->and(inWorkspaceOf($candidate, fn () => Claim::query()->latest('id')->first()->status))->toBe(ClaimStatus::Verified);

    $this->actingAs($candidate)
        ->get(route('applications.questions.index', $application))
        ->assertInertia(fn (Assert $page) => $page
            ->where('questions.0.answer.ai.to_check', 0)
            ->where('questions.0.answer.ai.claims.0.review.decision', 'accepted')
            ->where('questions.0.answer.ai.claims.1.review.claim_status', 'verified')
            ->where('questions.0.answer.ai.claims.2.to_check', false));

    $this->actingAs($candidate)->patch(route('applications.questions.approve', $routeArgs))->assertSessionHasNoErrors();
});

test('a VA can add a claim to the library only as a proposed bullet, which does not clear the review', function () {
    ['candidate' => $candidate, 'application' => $application, 'question' => $question] = reviewedDraftSetup();
    $va = vaOf($candidate);

    $this->actingAs($va)->post(route('applications.questions.reviews.accept', [$application, $question]), ['index' => 0])->assertForbidden();
    $this->actingAs($va)->post(route('applications.questions.reviews.library', [$application, $question]), ['item_type' => 'claim', 'index' => 0, 'statement' => 'Led a payments migration']);

    expect(inWorkspaceOf($candidate, fn () => Claim::query()->latest('id')->first()->status))->toBe(ClaimStatus::Proposed)
        ->and(reviewedDraftOf($candidate)->needsClaimReview())->toBeTrue();
});

test('an AI question is answered as a bullet point, or skipped', function () {
    ['candidate' => $candidate, 'application' => $application, 'question' => $question] = reviewedDraftSetup();

    $this->actingAs($candidate)
        ->post(route('applications.questions.reviews.library', [$application, $question]), ['item_type' => 'question', 'index' => 0, 'statement' => 'Moved [confirm: number] merchants'])
        ->assertSessionHasErrors('statement');

    $this->actingAs($candidate)->post(route('applications.questions.reviews.library', [$application, $question]), ['item_type' => 'question', 'index' => 0, 'statement' => 'Moved 1,200 merchants to the new processor']);
    expect(inWorkspaceOf($candidate, fn () => Claim::query()->latest('id')->first()->statement()))->toBe('Moved 1,200 merchants to the new processor');

    $this->actingAs($candidate)->post(route('applications.questions.reviews.dismiss', [$application, $question]), ['index' => 7])->assertSessionHasErrors('review');
});
