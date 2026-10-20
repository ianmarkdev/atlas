<?php

use App\Domain\Audit\AuditEvent;
use App\Domain\Tenancy\Role;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the candidate sees the team page with VAs and seat usage', function () {
    $candidate = candidateWithTwoFactor();
    $va = User::factory()->va(workspaceOf($candidate))->create(['name' => 'Vera Assistant']);

    $response = $this->actingAs($candidate)->get(route('team.index'));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('team/index')
        ->where('seatLimit', 3)
        ->where('activeVaCount', 1)
        ->has('vas', 1, fn (Assert $row) => $row
            ->where('ulid', $va->ulid)
            ->where('name', 'Vera Assistant')
            ->where('is_active', true)
            ->etc()));
});

test('VAs cannot open the team page', function () {
    $candidate = candidateWithTwoFactor();
    $va = User::factory()->va(workspaceOf($candidate))->create();

    $this->actingAs($va)->get(route('team.index'))->assertForbidden();
});

test('the candidate creates a VA account and gets a set-password link to share', function () {
    $candidate = candidateWithTwoFactor();

    $response = $this->actingAs($candidate)->post(route('team.store'), [
        'name' => 'Vera Assistant',
        'email' => 'Vera@Example.com',
    ]);

    $response->assertRedirect(route('team.index'))
        ->assertInertiaFlash('passwordLink.email', 'vera@example.com')
        ->assertInertiaFlash('passwordLink.url');

    $va = User::query()->where('email', 'vera@example.com')->firstOrFail();
    expect($va->role())->toBe(Role::Va)
        ->and($va->membership->workspace_id)->toBe(workspaceOf($candidate)->id)
        ->and($va->email_verified_at)->not->toBeNull();

    $event = app(TenantContext::class)->run(workspaceOf($candidate), fn () => AuditEvent::query()->sole());
    expect($event->action)->toBe('member.created')
        ->and($event->actor_user_id)->toBe($candidate->id)
        ->and($event->subject_id)->toBe($va->id);
});

test('creating a VA rejects an email that already has an account', function () {
    $candidate = candidateWithTwoFactor();

    $this->actingAs($candidate)
        ->post(route('team.store'), ['name' => 'Copy', 'email' => $candidate->email])
        ->assertSessionHasErrors(['email' => 'The email has already been taken.']);
});

test('a fourth active VA is rejected while three seats are in use', function () {
    $candidate = candidateWithTwoFactor();
    User::factory()->count(3)->va(workspaceOf($candidate))->create();

    $this->actingAs($candidate)
        ->post(route('team.store'), ['name' => 'Fourth', 'email' => 'fourth@example.com'])
        ->assertSessionHasErrors(['email' => 'All 3 VA seats are in use. Deactivate a VA first.']);

    $this->assertDatabaseMissing('users', ['email' => 'fourth@example.com']);
});

test('a deactivated VA frees a seat for a new one', function () {
    $candidate = candidateWithTwoFactor();
    User::factory()->count(2)->va(workspaceOf($candidate))->create();
    User::factory()->inactive()->va(workspaceOf($candidate))->create();

    $this->actingAs($candidate)
        ->post(route('team.store'), ['name' => 'Third active', 'email' => 'third@example.com'])
        ->assertSessionHasNoErrors();
});

test('the candidate deactivates a VA', function () {
    $candidate = candidateWithTwoFactor();
    $va = User::factory()->va(workspaceOf($candidate))->create();

    $this->actingAs($candidate)
        ->patch(route('team.deactivate', $va))
        ->assertRedirect(route('team.index'));

    expect($va->refresh()->is_active)->toBeFalse();
});

test('reactivating a VA is rejected while all seats are in use', function () {
    $candidate = candidateWithTwoFactor();
    User::factory()->count(3)->va(workspaceOf($candidate))->create();
    $inactive = User::factory()->inactive()->va(workspaceOf($candidate))->create();

    $this->actingAs($candidate)
        ->patch(route('team.reactivate', $inactive))
        ->assertSessionHasErrors('seats');

    expect($inactive->refresh()->is_active)->toBeFalse();
});

test('the candidate gets a new set-password link for a VA', function () {
    $candidate = candidateWithTwoFactor();
    $va = User::factory()->va(workspaceOf($candidate))->create();

    $this->actingAs($candidate)
        ->post(route('team.password-link', $va))
        ->assertRedirect(route('team.index'))
        ->assertInertiaFlash('passwordLink.email', $va->email);
});

test('team actions on a VA of another workspace respond 404', function () {
    $candidate = candidateWithTwoFactor();
    $otherCandidate = User::factory()->candidate()->create();
    $otherVa = User::factory()->va(workspaceOf($otherCandidate))->create();

    $this->actingAs($candidate)->patch(route('team.deactivate', $otherVa))->assertNotFound();
    $this->actingAs($candidate)->post(route('team.password-link', $otherVa))->assertNotFound();

    expect($otherVa->refresh()->is_active)->toBeTrue();
});

test('team actions on the candidate account respond 404', function () {
    $candidate = candidateWithTwoFactor();

    $this->actingAs($candidate)->patch(route('team.deactivate', $candidate))->assertNotFound();

    expect($candidate->refresh()->is_active)->toBeTrue();
});
