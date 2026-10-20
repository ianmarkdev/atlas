<?php

use App\Models\User;

test('a deactivated user cannot sign in with a password', function () {
    $user = User::factory()->inactive()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('a deactivated user with an open session is signed out on the next request', function () {
    $candidate = User::factory()->withTwoFactor()->candidate()->create();
    $va = User::factory()->va($candidate->membership->workspace)->create();
    $va->forceFill(['is_active' => false])->save();

    $this->actingAs($va)->get(route('dashboard'))->assertRedirect(route('login'));

    $this->assertGuest();
});

test('a signed-in user without a workspace membership is forbidden from the app', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('dashboard'))->assertForbidden();
});

test('the candidate is sent to security settings until two-factor authentication is enabled', function () {
    $candidate = User::factory()->candidate()->create();

    $this->actingAs($candidate)
        ->get(route('dashboard'))
        ->assertRedirect(route('security.edit'));
});

test('a VA without two-factor authentication can use the app', function () {
    $candidate = User::factory()->withTwoFactor()->candidate()->create();
    $va = User::factory()->va($candidate->membership->workspace)->create();

    $this->actingAs($va)->get(route('dashboard'))->assertOk();
});

test('self-registration is disabled', function () {
    $this->get('/register')->assertNotFound();
});

test('signing in records the last login time', function () {
    $this->freezeTime();
    $user = User::factory()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    expect($user->refresh()->last_login_at?->toIso8601String())->toBe(now()->toIso8601String());
});
