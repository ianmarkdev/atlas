<?php

use App\Models\User;

test('only the candidate holds candidate abilities', function (string $ability) {
    $candidate = User::factory()->candidate()->create();
    $va = User::factory()->va($candidate->membership->workspace)->create();

    expect($candidate->can($ability))->toBeTrue()
        ->and($va->can($ability))->toBeFalse();
})->with(['manage-vas', 'manage-budget']);

test('the candidate cannot delete their own account from settings', function () {
    $candidate = User::factory()->withTwoFactor()->candidate()->create();

    $this->actingAs($candidate)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertForbidden();

    expect($candidate->fresh())->not->toBeNull();
});
