<?php

use App\Domain\Ai\Clients\FakeLlmClient;
use App\Domain\Ai\LlmRouter;
use App\Domain\Knowledge\Claim;
use App\Domain\Knowledge\ClaimKind;
use App\Domain\Knowledge\ClaimStatus;
use App\Domain\Knowledge\KnowledgeProposal;
use App\Domain\Knowledge\ProposalStatus;
use App\Jobs\SuggestAchievements;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

function suggestionsOf(User $candidate): mixed
{
    return inWorkspaceOf($candidate, fn () => KnowledgeProposal::query()->where('origin', 'achievement_suggestion')->with('target.currentRevision')->get());
}

function duty(User $candidate, string $statement): Claim
{
    $claim = claimOf($candidate, $statement, employmentOf($candidate));
    inWorkspaceOf($candidate, fn () => $claim->forceFill(['kind' => ClaimKind::Responsibility])->save());

    return $claim;
}

test('the AI pass stores checked suggestions only for plain bullet points', function () {
    $candidate = candidateWithTwoFactor();
    $plain = duty($candidate, 'Maintained the billing platform');
    duty($candidate, 'Cut the invoice run from 4 hours to 20 minutes');
    $other = duty($candidate, 'Ran the on-call rota');
    // Newest first, and the bullet with a number is left out: C1 = on-call rota, C2 = billing platform.
    $fake = app(LlmRouter::class)->fake(new FakeLlmClient([['suggestions' => [
        ['id' => 'C1', 'question' => 'Did incidents drop? By how much?', 'draft' => 'Ran the on-call rota, cutting incidents by [confirm: % fewer incidents]'],
        // Invents a number outside a placeholder: refused.
        ['id' => 'C2', 'question' => 'For how long?', 'draft' => 'Kept the billing platform running for 12 months, [confirm: uptime]'],
        ['id' => 'C9', 'question' => 'Unknown?', 'draft' => 'Something [confirm: x]'],
    ]]]));

    $this->actingAs($candidate)
        ->post(route('knowledge.proposals.suggest'))
        ->assertInertiaFlash('toast.message', 'Looking for bullet points that could show a result. Suggestions appear here.');

    $prompt = $fake->requests[0]->messages[0]['content'];
    $suggestions = suggestionsOf($candidate);
    expect($prompt)->toContain('Maintained the billing platform')
        ->and($prompt)->not->toContain('4 hours')
        ->and($prompt)->toContain('<bullet id="C2" where="'.$plain->subject->title)
        ->and($suggestions)->toHaveCount(1)
        ->and($suggestions[0]->target_id)->toBe($other->id)
        ->and($suggestions[0]->payload['statement'])->toBe('Ran the on-call rota, cutting incidents by [confirm: % fewer incidents]')
        ->and($suggestions[0]->status)->toBe(ProposalStatus::Pending)
        ->and(Cache::get(SuggestAchievements::statusKey(workspaceOf($candidate)->id)))->toBeNull();

    $this->actingAs($candidate)
        ->get(route('knowledge.proposals.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('pending.0.origin', 'achievement_suggestion')
            ->where('pending.0.suggestion.question', $suggestions[0]->rationale));
});

test('answering a suggestion revises the bullet as a verified achievement; placeholders must be filled first', function () {
    $candidate = candidateWithTwoFactor();
    $claim = duty($candidate, 'Maintained the billing platform');
    app(LlmRouter::class)->fake(new FakeLlmClient([['suggestions' => [
        ['id' => 'C1', 'question' => 'By how much?', 'draft' => 'Kept the billing platform fast, cutting failures by [confirm: %]'],
    ]]]));
    $this->actingAs($candidate)->post(route('knowledge.proposals.suggest'));
    $proposal = suggestionsOf($candidate)->sole();

    $this->actingAs($candidate)
        ->patch(route('knowledge.proposals.approve', $proposal), ['statement' => 'Kept the billing platform fast, cutting failures by [confirm: %]'])
        ->assertSessionHasErrors('statement');

    $this->actingAs($candidate)->patch(route('knowledge.proposals.approve', $proposal), ['statement' => 'Kept the billing platform fast, cutting failed payments by 40%']);

    $claim = inWorkspaceOf($candidate, fn () => $claim->fresh(['currentRevision']));
    expect($claim->statement())->toBe('Kept the billing platform fast, cutting failed payments by 40%')
        ->and($claim->status)->toBe(ClaimStatus::Verified)
        ->and($claim->kind)->toBe(ClaimKind::Achievement)
        ->and(suggestionsOf($candidate)->sole()->status)->toBe(ProposalStatus::Approved);
});

test('a VA cannot approve or request suggestions, and a rejected suggestion is not asked again', function () {
    $candidate = candidateWithTwoFactor();
    duty($candidate, 'Maintained the billing platform');
    $response = ['suggestions' => [['id' => 'C1', 'question' => 'By how much?', 'draft' => 'Kept it fast by [confirm: %]']]];
    $fake = app(LlmRouter::class)->fake(new FakeLlmClient([$response, $response]));
    $this->actingAs($candidate)->post(route('knowledge.proposals.suggest'));
    $proposal = suggestionsOf($candidate)->sole();

    $this->actingAs(vaOf($candidate))->post(route('knowledge.proposals.suggest'))->assertForbidden();
    $this->actingAs(vaOf($candidate))->patch(route('knowledge.proposals.approve', $proposal), ['statement' => 'Kept it fast by 40%'])->assertForbidden();

    $this->actingAs($candidate)->patch(route('knowledge.proposals.reject', $proposal));
    $this->actingAs($candidate)->post(route('knowledge.proposals.suggest'));

    // Nothing left to ask about: the model is not called a second time.
    expect($fake->requests)->toHaveCount(1)
        ->and(suggestionsOf($candidate))->toHaveCount(1);
});
