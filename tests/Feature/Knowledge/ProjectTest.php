<?php

use App\Domain\Knowledge\Employment;
use App\Domain\Knowledge\KnowledgeStatus;
use App\Domain\Knowledge\Project;

test('the candidate adds a verified project linked to an employment', function () {
    $candidate = candidateWithTwoFactor();
    $employment = Employment::factory()->forProfile(profileOf($candidate))->verified($candidate)->create();

    $this->actingAs($candidate)
        ->post(route('knowledge.projects.store'), [
            'employment' => $employment->ulid,
            'name' => 'Billing rewrite',
            'role' => 'Tech lead',
            'start_date' => '2022-01',
            'url' => 'https://example.com/case-study',
        ])
        ->assertRedirect(route('knowledge.index'));

    $project = inWorkspaceOf($candidate, fn () => Project::query()->sole());
    expect($project->employment_id)->toBe($employment->id)
        ->and($project->status)->toBe(KnowledgeStatus::Verified)
        ->and($project->start_date?->toDateString())->toBe('2022-01-01');
});

test('a project without an employment is standalone', function () {
    $candidate = candidateWithTwoFactor();

    $this->actingAs($candidate)
        ->post(route('knowledge.projects.store'), ['employment' => '', 'name' => 'Open-source package'])
        ->assertSessionHasNoErrors();

    expect(inWorkspaceOf($candidate, fn () => Project::query()->sole()->employment_id))->toBeNull();
});

test('a project cannot be linked to an employment of another workspace', function () {
    $candidate = candidateWithTwoFactor();
    $foreign = Employment::factory()->forProfile(profileOf(candidateWithTwoFactor()))->create();

    $this->actingAs($candidate)
        ->post(route('knowledge.projects.store'), ['employment' => $foreign->ulid, 'name' => 'Sneaky'])
        ->assertSessionHasErrors(['employment' => 'The selected employment is invalid.']);
});

test('a project cannot be linked to an archived employment', function () {
    $candidate = candidateWithTwoFactor();
    $archived = Employment::factory()->forProfile(profileOf($candidate))->archived()->create();

    $this->actingAs($candidate)
        ->post(route('knowledge.projects.store'), ['employment' => $archived->ulid, 'name' => 'Late addition'])
        ->assertSessionHasErrors('employment');
});

test('a project link that is not http or https is rejected', function () {
    $this->actingAs(candidateWithTwoFactor())
        ->post(route('knowledge.projects.store'), ['name' => 'Demo', 'url' => 'javascript:alert(1)'])
        ->assertSessionHasErrors(['url' => 'The url field must be a valid URL.']);
});

test('a VA cannot edit a verified project', function () {
    $candidate = candidateWithTwoFactor();
    $project = Project::factory()->forProfile(profileOf($candidate))->verified($candidate)->create(['name' => 'Original']);

    $this->actingAs(vaOf($candidate))
        ->put(route('knowledge.projects.update', $project), ['name' => 'Changed', 'lock_version' => 0])
        ->assertForbidden();

    expect($project->refresh()->name)->toBe('Original');
});
