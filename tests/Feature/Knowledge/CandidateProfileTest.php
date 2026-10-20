<?php

test('the candidate updates their contact details as normalised plain text', function () {
    $candidate = candidateWithTwoFactor();

    $this->actingAs($candidate)
        ->patch(route('contact.update'), [
            'display_name' => "  Alex\u{200B}  Morgan ",
            'email' => 'Alex.Morgan@Example.com',
            'phone' => '+44 20 7946 0000',
            'location' => 'London, UK',
        ])
        ->assertRedirect(route('resumes.index'));

    $profile = profileOf($candidate);
    expect($profile->display_name)->toBe('Alex Morgan')
        ->and($profile->phone)->toBe('+44 20 7946 0000')
        ->and($profile->location)->toBe('London, UK');
});

test('a phone number with letters is rejected', function () {
    $this->actingAs(candidateWithTwoFactor())
        ->patch(route('contact.update'), ['display_name' => 'Alex Morgan', 'phone' => 'call me'])
        ->assertSessionHasErrors(['phone' => 'Use digits, spaces and + ( ) - . only.']);
});

test('blank optional contact fields are stored as empty', function () {
    $candidate = candidateWithTwoFactor();

    $this->actingAs($candidate)->patch(route('contact.update'), [
        'display_name' => 'Alex Morgan',
        'location' => '   ',
    ]);

    expect(profileOf($candidate)->location)->toBeNull();
});

test('VAs cannot edit the contact details', function () {
    $candidate = candidateWithTwoFactor();

    $this->actingAs(vaOf($candidate))
        ->patch(route('contact.update'), ['display_name' => 'Someone else'])
        ->assertForbidden();

    expect(profileOf($candidate)->display_name)->not->toBe('Someone else');
});
