<?php

use App\Domain\Knowledge\Skill;

test('skills added as a comma-separated list reuse existing ones that differ only in case or punctuation', function () {
    $candidate = candidateWithTwoFactor();
    Skill::factory()->inWorkspace(workspaceOf($candidate))->create(['name' => 'Next.js']);

    $this->actingAs($candidate)
        ->postJson(route('knowledge.skills.store'), ['names' => "PHP, laravel,\n next js , Laravel"])
        ->assertCreated()
        ->assertJsonCount(3, 'skills');

    $names = inWorkspaceOf($candidate, fn () => Skill::query()->alphabetical()->pluck('name')->all());
    expect($names)->toBe(['laravel', 'Next.js', 'PHP']);
});

test('renaming a skill to one that already exists is rejected', function () {
    $candidate = candidateWithTwoFactor();
    $php = Skill::factory()->inWorkspace(workspaceOf($candidate))->create(['name' => 'PHP']);
    Skill::factory()->inWorkspace(workspaceOf($candidate))->create(['name' => 'Laravel']);

    $this->actingAs($candidate)
        ->patch(route('knowledge.skills.update', $php), ['name' => 'laravel'])
        ->assertSessionHasErrors(['name' => 'You already have this skill.']);
});

test('the candidate removes a skill', function () {
    $candidate = candidateWithTwoFactor();
    $skill = Skill::factory()->inWorkspace(workspaceOf($candidate))->create(['name' => 'Perl']);

    $this->actingAs($candidate)->delete(route('knowledge.skills.destroy', $skill))->assertRedirect();

    expect(inWorkspaceOf($candidate, fn () => Skill::query()->count()))->toBe(0);
});

test('VAs cannot change the skill list', function () {
    $candidate = candidateWithTwoFactor();

    $this->actingAs(vaOf($candidate))
        ->post(route('knowledge.skills.store'), ['names' => 'PHP'])
        ->assertForbidden();
});
