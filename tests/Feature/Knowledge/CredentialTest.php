<?php

use App\Domain\Knowledge\Credential;
use App\Domain\Knowledge\CredentialType;
use App\Domain\Knowledge\KnowledgeStatus;

test('education the candidate adds is verified, with months stored as dates', function () {
    $candidate = candidateWithTwoFactor();

    $this->actingAs($candidate)
        ->post(route('knowledge.credentials.store'), [
            'type' => 'education',
            'institution' => 'University of Leeds',
            'name' => 'BSc',
            'field_of_study' => 'Computer Science',
            'start_date' => '2013-09',
            'end_date' => '2016-07',
        ])
        ->assertRedirect(route('knowledge.index'));

    $credential = inWorkspaceOf($candidate, fn () => Credential::query()->sole());
    expect($credential->type)->toBe(CredentialType::Education)
        ->and($credential->status)->toBe(KnowledgeStatus::Verified)
        ->and($credential->end_date?->toDateString())->toBe('2016-07-01');
});

test('a certification a VA adds stays proposed', function () {
    $candidate = candidateWithTwoFactor();

    $this->actingAs(vaOf($candidate))->post(route('knowledge.credentials.store'), [
        'type' => 'certification',
        'institution' => 'Amazon Web Services',
        'name' => 'AWS Certified Developer',
        'end_date' => '2023-05',
    ]);

    expect(inWorkspaceOf($candidate, fn () => Credential::query()->sole()->status))->toBe(KnowledgeStatus::Proposed);
});

test('adding education as JSON returns the new record', function () {
    $this->actingAs(candidateWithTwoFactor())
        ->postJson(route('knowledge.credentials.store'), [
            'type' => 'education',
            'institution' => 'University of Leeds',
            'name' => 'MSc',
        ])
        ->assertCreated()
        ->assertJsonPath('credential.name', 'MSc')
        ->assertJsonPath('credential.status', 'verified');
});

test('an end month before the start month is rejected', function () {
    $this->actingAs(candidateWithTwoFactor())
        ->post(route('knowledge.credentials.store'), [
            'type' => 'education',
            'institution' => 'University of Leeds',
            'name' => 'BSc',
            'start_date' => '2016-09',
            'end_date' => '2013-07',
        ])
        ->assertSessionHasErrors('end_date');
});

test('a VA cannot edit verified education', function () {
    $candidate = candidateWithTwoFactor();
    $credential = Credential::factory()->forProfile(profileOf($candidate))->verified($candidate)->create(['name' => 'BSc']);

    $this->actingAs(vaOf($candidate))
        ->put(route('knowledge.credentials.update', $credential), [
            'type' => 'education',
            'institution' => 'Somewhere',
            'name' => 'PhD',
            'lock_version' => 0,
        ])
        ->assertForbidden();

    expect($credential->refresh()->name)->toBe('BSc');
});

test('a credential of another workspace responds 404', function () {
    $candidate = candidateWithTwoFactor();
    $foreign = Credential::factory()->forProfile(profileOf(candidateWithTwoFactor()))->create();

    $this->actingAs($candidate)->get(route('knowledge.credentials.edit', $foreign))->assertNotFound();
});
