<?php

use App\Domain\Knowledge\CandidateLanguage;
use App\Domain\Knowledge\CandidateLink;

test('the candidate adds and removes a language', function () {
    $candidate = candidateWithTwoFactor();

    $this->actingAs($candidate)
        ->post(route('contact.languages.store'), ['language' => ' German ', 'proficiency' => 'professional'])
        ->assertSessionHasNoErrors();

    $language = inWorkspaceOf($candidate, fn () => CandidateLanguage::query()->sole());
    expect($language->language)->toBe('German');

    $this->actingAs($candidate)->delete(route('contact.languages.destroy', $language));

    expect(inWorkspaceOf($candidate, fn () => CandidateLanguage::query()->count()))->toBe(0);
});

test('the same language cannot be added twice', function () {
    $candidate = candidateWithTwoFactor();
    $this->actingAs($candidate)->post(route('contact.languages.store'), ['language' => 'German', 'proficiency' => 'basic']);

    $this->actingAs($candidate)
        ->post(route('contact.languages.store'), ['language' => 'German', 'proficiency' => 'fluent'])
        ->assertSessionHasErrors(['language' => 'This language is already on your list.']);
});

test('the candidate adds a link; a non-http link is rejected', function () {
    $candidate = candidateWithTwoFactor();

    $this->actingAs($candidate)
        ->post(route('contact.links.store'), ['kind' => 'github', 'url' => 'https://github.com/alex'])
        ->assertSessionHasNoErrors();

    $this->actingAs($candidate)
        ->post(route('contact.links.store'), ['kind' => 'other', 'url' => 'javascript:alert(1)'])
        ->assertSessionHasErrors('url');

    expect(inWorkspaceOf($candidate, fn () => CandidateLink::query()->sole()->displayLabel()))->toBe('GitHub');
});

test('VAs cannot change languages or links', function () {
    $va = vaOf(candidateWithTwoFactor());

    $this->actingAs($va)->post(route('contact.languages.store'), ['language' => 'German', 'proficiency' => 'basic'])->assertForbidden();
    $this->actingAs($va)->post(route('contact.links.store'), ['kind' => 'github', 'url' => 'https://github.com/x'])->assertForbidden();
});
