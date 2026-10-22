<?php

use App\Domain\Applications\JdCollection;
use App\Domain\Applications\JobApplication;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function collectionNamed(User $candidate, string $name, bool $forEval = false): JdCollection
{
    test()->actingAs($candidate)->post(route('collections.store'), ['name' => $name, 'use_for_eval' => $forEval]);

    return inWorkspaceOf($candidate, fn () => JdCollection::query()->where('name', $name)->sole());
}

test('the candidate creates a collection, marks it for evaluation and adds applications to it', function () {
    $candidate = candidateWithTwoFactor();
    $collection = collectionNamed($candidate, 'Fintech roles', forEval: true);
    $inside = JobApplication::factory()->forProfile(profileOf($candidate))->create(['company_name' => 'Inside']);
    JobApplication::factory()->forProfile(profileOf($candidate))->create(['company_name' => 'Outside']);

    $this->actingAs($candidate)->patch(route('applications.collections.toggle', $inside), ['collection' => $collection->ulid, 'member' => true])->assertRedirect();
    $this->actingAs($candidate)->patch(route('applications.collections.toggle', $inside), ['collection' => $collection->ulid, 'member' => true]);

    expect($collection->use_for_eval)->toBeTrue()
        ->and(inWorkspaceOf($candidate, fn () => $collection->applications()->count()))->toBe(1);

    $this->actingAs($candidate)->get(route('applications.index', ['collection' => $collection->ulid]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('applications', 1)
            ->where('applications.0.company_name', 'Inside')
            ->where('collection.name', 'Fintech roles'));

    $this->actingAs($candidate)->get(route('collections.index'))
        ->assertInertia(fn (Assert $page) => $page->where('collections.0.applications', 1)->where('canMarkEval', true));

    $this->actingAs($candidate)->patch(route('applications.collections.toggle', $inside), ['collection' => $collection->ulid, 'member' => false]);
    expect(inWorkspaceOf($candidate, fn () => $collection->applications()->count()))->toBe(0);
});

test('a VA manages collections but cannot decide the evaluation set', function () {
    $candidate = candidateWithTwoFactor();
    $va = vaOf($candidate);

    $collection = collectionNamed($va, 'Remote roles', forEval: true);
    expect($collection->use_for_eval)->toBeFalse();

    $marked = collectionNamed($candidate, 'Eval set', forEval: true);
    $this->actingAs($va)->put(route('collections.update', $marked), ['name' => 'Eval set (renamed)', 'use_for_eval' => false]);

    expect($marked->refresh()->name)->toBe('Eval set (renamed)')
        ->and($marked->use_for_eval)->toBeTrue();
});

test('deleting a collection keeps its applications', function () {
    $candidate = candidateWithTwoFactor();
    $collection = collectionNamed($candidate, 'Old');
    $application = JobApplication::factory()->forProfile(profileOf($candidate))->create();
    $this->actingAs($candidate)->patch(route('applications.collections.toggle', $application), ['collection' => $collection->ulid, 'member' => true]);

    $this->actingAs($candidate)->delete(route('collections.destroy', $collection))->assertRedirect();

    expect(inWorkspaceOf($candidate, fn () => JdCollection::query()->count()))->toBe(0)
        ->and($application->refresh()->exists)->toBeTrue();
});

test('another workspace\'s collection cannot be used', function () {
    $candidate = candidateWithTwoFactor();
    $foreign = collectionNamed(candidateWithTwoFactor(), 'Theirs');
    $application = JobApplication::factory()->forProfile(profileOf($candidate))->create();

    $this->actingAs($candidate)
        ->patch(route('applications.collections.toggle', $application), ['collection' => $foreign->ulid, 'member' => true])
        ->assertSessionHasErrors('collection');
    $this->actingAs($candidate)->delete(route('collections.destroy', $foreign))->assertNotFound();
});
