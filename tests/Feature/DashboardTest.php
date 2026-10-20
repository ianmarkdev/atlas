<?php

use App\Domain\Knowledge\AnswerLibraryEntry;
use App\Domain\Knowledge\Claim;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->withTwoFactor()->candidate()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('the candidate sees counts, open work and the setup checklist', function () {
    $candidate = candidateWithTwoFactor();
    $employment = employmentOf($candidate);
    claimOf($candidate, 'Led a team of four', $employment);
    Claim::factory()->forProfile(profileOf($candidate))->stating('Speaks German')->create();
    AnswerLibraryEntry::factory()->forProfile(profileOf($candidate))->answering('One month.')->create();

    $this->actingAs($candidate)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('isCandidate', true)
            ->where('stats.jobs', 1)
            ->where('stats.bullets', 2)
            ->where('stats.bulletsProposed', 1)
            ->where('stats.answersDraft', 1)
            ->where('attention', [
                ['key' => 'unverified', 'label' => 'Library items to verify', 'count' => 1, 'href' => route('knowledge.index', ['show' => 'unverified'])],
                ['key' => 'drafts', 'label' => 'Draft answers to approve', 'count' => 1, 'href' => route('knowledge.answers.index')],
            ])
            ->where('checklist.1.key', 'experience')
            ->where('checklist.1.done', true)
            ->where('checklist.2.done', false)
            ->where('checklist.6.key', 'security')
            ->where('checklist.6.done', true));
});

test('a VA sees their own pending suggestions and no setup checklist', function () {
    $candidate = candidateWithTwoFactor();
    $va = vaOf($candidate);
    $claim = claimOf($candidate, 'Led a team of four');
    $this->actingAs($va)->put(route('knowledge.claims.update', $claim), [
        'kind' => 'achievement',
        'statement' => 'Led a team of five',
        'lock_version' => 0,
    ]);

    $this->actingAs($va)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('isCandidate', false)
            ->where('checklist', null)
            ->where('attention.0.key', 'suggestions')
            ->where('attention.0.count', 1));

    $this->actingAs(vaOf($candidate))->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('attention', []));
});

test('recent activity describes events in words, with the subject and who did it', function () {
    $candidate = candidateWithTwoFactor();
    $this->actingAs($candidate)->post(route('knowledge.claims.store'), [
        'subject' => 'general',
        'kind' => 'achievement',
        'statement' => 'Cut the nightly invoice run to 20 minutes',
    ]);
    $this->actingAs(vaOf($candidate))->post(route('knowledge.answers.store'), [
        'question' => 'Notice period?',
        'category' => 'availability',
        'body' => 'One month.',
    ]);

    $this->actingAs($candidate)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('activity.0.sentence', 'added an answer')
            ->where('activity.0.subject', 'Notice period?')
            ->where('activity.0.is_you', false)
            ->where('activity.1.sentence', 'added a bullet point')
            ->where('activity.1.subject', 'Cut the nightly invoice run to 20 minutes')
            ->where('activity.1.actor', $candidate->name)
            ->where('activity.1.is_you', true));
});

test('the dashboard shows only the signed-in workspace', function () {
    $other = candidateWithTwoFactor();
    employmentOf($other);
    claimOf($other, 'Someone else');

    $this->actingAs(candidateWithTwoFactor())->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.jobs', 0)
            ->where('stats.bullets', 0)
            ->where('activity', []));
});
