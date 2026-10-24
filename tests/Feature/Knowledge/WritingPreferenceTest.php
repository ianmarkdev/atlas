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

test('a rule in place can be narrowed to some drafts, by whoever may remove it', function () {
    $candidate = candidateWithTwoFactor();
    $va = vaOf($candidate);
    addRule($candidate, 'Sign off with my first name');
    addRule($va, 'Keep paragraphs short', personal: true);
    [$shared, $personal] = rulesOf($candidate)->all();

    $this->actingAs($va)->patch(route('knowledge.preferences.drafts', $shared), ['drafts' => 'cover_letter'])->assertForbidden();
    $this->actingAs($candidate)->patch(route('knowledge.preferences.drafts', $personal), ['drafts' => 'question'])->assertForbidden();
    $this->actingAs($candidate)->patch(route('knowledge.preferences.drafts', $shared), ['drafts' => 'cover_letter'])->assertSessionHasNoErrors();
    $this->actingAs($va)->patch(route('knowledge.preferences.drafts', $personal), ['drafts' => 'question'])->assertSessionHasNoErrors();

    expect(rulesOf($candidate)->map(fn (WritingPreference $rule): string => $rule->drafts->value)->all())->toBe(['cover_letter', 'question']);
});

test('a rule like one in place is held back until it is added anyway or replaces the other', function () {
    $candidate = candidateWithTwoFactor();
    addRule($candidate, 'Use British spelling');
    $old = rulesOf($candidate)->sole();

    addRule($candidate, 'Use British punctuation')
        ->assertInertiaFlash('similarRules.rule', 'Use British punctuation')
        ->assertInertiaFlash('similarRules.similar.0.ulid', $old->ulid)
        ->assertInertiaFlash('similarRules.similar.0.can_replace', true);
    expect(rulesOf($candidate))->toHaveCount(1);

    $this->actingAs($candidate)->post(route('knowledge.preferences.store'), ['rule' => 'Use British punctuation', 'kind' => 'spelling', 'confirmed' => true])
        ->assertInertiaFlash('toast.message', 'Rule added. New drafts follow it.');
    $this->actingAs($candidate)->post(route('knowledge.preferences.store'), ['rule' => 'Use British spelling and punctuation', 'kind' => 'spelling', 'replaces' => $old->ulid])
        ->assertInertiaFlash('toast.message', 'Rule replaced. New drafts follow the new one.');

    expect(rulesOf($candidate)->pluck('rule')->all())->toBe(['Use British punctuation', 'Use British spelling and punctuation']);
});

test("a VA's suggestion may name a rule for everyone to replace, which only the candidate can do", function () {
    $candidate = candidateWithTwoFactor();
    $va = vaOf($candidate);
    addRule($candidate, 'Use British spelling');
    $old = rulesOf($candidate)->sole();

    addRule($va, 'Use British punctuation')->assertInertiaFlash('similarRules.similar.0.ulid', $old->ulid);
    $this->actingAs($va)->post(route('knowledge.preferences.store'), ['rule' => 'Use British punctuation', 'kind' => 'spelling', 'personal' => true, 'replaces' => $old->ulid])
        ->assertSessionHasErrors(['replaces' => 'You cannot replace that rule.']);
    $this->actingAs($va)->post(route('knowledge.preferences.store'), ['rule' => 'Use British punctuation', 'kind' => 'spelling', 'replaces' => $old->ulid]);

    expect(inWorkspaceOf($candidate, fn () => KnowledgeProposal::query()->sole()->payload['replaces']))->toBe($old->id)
        ->and(rulesOf($candidate)->pluck('rule')->all())->toBe(['Use British spelling']);
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
