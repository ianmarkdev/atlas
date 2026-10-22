<?php

use App\Domain\Knowledge\KnowledgeProposal;
use App\Domain\Knowledge\WritingPreference;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function addRule(User $user, string $rule, bool $personal = false): mixed
{
    return test()->actingAs($user)->post(route('knowledge.preferences.store'), ['rule' => $rule, 'kind' => 'spelling', 'personal' => $personal]);
}

function rulesOf(User $candidate): mixed
{
    return inWorkspaceOf($candidate, fn () => WritingPreference::query()->where('status', 'active')->orderBy('id')->get());
}

test('the candidate adds a rule for every draft; the same rule twice is refused', function () {
    $candidate = candidateWithTwoFactor();

    addRule($candidate, 'Use British spelling')->assertInertiaFlash('toast.message', 'Rule added. New drafts follow it.');
    addRule($candidate, 'use british spelling!')->assertSessionHasErrors(['rule' => 'This rule is already in the list.']);

    $rule = rulesOf($candidate)->sole();
    expect($rule->scope)->toBe('profile')
        ->and($rule->user_id)->toBeNull();
});

test("a VA's rule for everyone is suggested to the candidate; a personal rule is theirs alone", function () {
    $candidate = candidateWithTwoFactor();
    $va = vaOf($candidate);

    addRule($va, 'Use British spelling')->assertInertiaFlash('toast.message', 'Rule suggested. It applies to every draft once the candidate approves it.');
    addRule($va, 'Keep paragraphs short', personal: true);

    $proposal = inWorkspaceOf($candidate, fn () => KnowledgeProposal::query()->sole());
    expect($proposal->proposal_type)->toBe('create_writing_preference')
        ->and($proposal->reviewer_user_id)->toBeNull()
        ->and(rulesOf($candidate)->pluck('rule')->all())->toBe(['Keep paragraphs short']);

    $this->actingAs($candidate)
        ->get(route('knowledge.preferences.index'))
        ->assertInertia(fn (Assert $page) => $page->has('shared', 0)->has('personal', 0)->where('suggested', 1));
    $this->actingAs($va)
        ->get(route('knowledge.preferences.index'))
        ->assertInertia(fn (Assert $page) => $page->where('personal.0.rule', 'Keep paragraphs short')->where('personal.0.can.archive', true));
});

test('only the candidate removes a rule for everyone; each user removes their own', function () {
    $candidate = candidateWithTwoFactor();
    $va = vaOf($candidate);
    addRule($candidate, 'Use British spelling');
    addRule($va, 'Keep paragraphs short', personal: true);
    [$shared, $personal] = rulesOf($candidate)->all();

    $this->actingAs($va)->patch(route('knowledge.preferences.archive', $shared))->assertForbidden();
    $this->actingAs($candidate)->patch(route('knowledge.preferences.archive', $personal))->assertForbidden();
    $this->actingAs($va)->patch(route('knowledge.preferences.archive', $personal))->assertSessionHasNoErrors();

    expect(rulesOf($candidate)->pluck('rule')->all())->toBe(['Use British spelling']);
});
