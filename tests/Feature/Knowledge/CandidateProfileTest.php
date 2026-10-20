<?php

test('the candidate updates the display name and headline as normalised plain text', function () {
    $candidate = candidateWithTwoFactor();

    $this->actingAs($candidate)
        ->patch(route('knowledge.profile.update'), [
            'display_name' => "  Alex\u{200B}  Morgan ",
            'headline' => 'Senior Laravel developer',
        ])
        ->assertRedirect(route('knowledge.index'));

    $profile = profileOf($candidate);
    expect($profile->display_name)->toBe('Alex Morgan')
        ->and($profile->headline)->toBe('Senior Laravel developer');
});

test('a blank headline is stored as empty', function () {
    $candidate = candidateWithTwoFactor();

    $this->actingAs($candidate)->patch(route('knowledge.profile.update'), [
        'display_name' => 'Alex Morgan',
        'headline' => '   ',
    ]);

    expect(profileOf($candidate)->headline)->toBeNull();
});

test('VAs cannot edit the candidate profile', function () {
    $candidate = candidateWithTwoFactor();

    $this->actingAs(vaOf($candidate))
        ->patch(route('knowledge.profile.update'), ['display_name' => 'Someone else'])
        ->assertForbidden();

    expect(profileOf($candidate)->display_name)->not->toBe('Someone else');
});
