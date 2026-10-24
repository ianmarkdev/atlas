<?php

use App\Domain\Ai\Clients\FakeLlmClient;
use App\Domain\Ai\LlmRouter;
use App\Domain\Applications\ClarificationRequest;
use App\Domain\Applications\JobApplication;
use App\Domain\Knowledge\Claim;
use App\Domain\Knowledge\ClaimStatus;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * An analysed application whose must-haves are PostgreSQL (covered) and Kubernetes (a gap).
 */
function applicationWithGap(User $candidate): JobApplication
{
    $application = JobApplication::factory()->forProfile(profileOf($candidate))
        ->withJobDescription('You have PostgreSQL tuning experience and run Kubernetes clusters.')
        ->create();
    claimOf($candidate, 'Tuned PostgreSQL queries, halving p95 latency');

    app(LlmRouter::class)->fake(new FakeLlmClient([[
        'summary' => 'Platform role.', 'seniority' => 'senior',
        'must_haves' => [
            ['requirement' => 'PostgreSQL tuning', 'quote' => 'PostgreSQL tuning experience'],
            ['requirement' => 'Kubernetes operations', 'quote' => 'run Kubernetes clusters'],
        ],
        'nice_to_haves' => [], 'employer_language' => [], 'questions' => [], 'red_flags' => [], 'injection_warnings' => [],
    ]]));
    test()->actingAs($candidate)->post(route('applications.analysis.store', $application));

    return $application;
}

function clarificationsOf(User $candidate): mixed
{
    return inWorkspaceOf($candidate, fn () => ClarificationRequest::query()->orderBy('id')->get());
}

test('a must-have without evidence becomes an open question; covered ones do not', function () {
    $candidate = candidateWithTwoFactor();
    $application = applicationWithGap($candidate);

    $requests = clarificationsOf($candidate);
    expect($requests)->toHaveCount(1)
        ->and($requests[0]->requirement)->toBe('Kubernetes operations')
        ->and($requests[0]->status)->toBe('open')
        ->and($requests[0]->question_text)->toContain('“Kubernetes operations”');

    $this->actingAs($candidate)->get(route('applications.analysis.show', $application))
        ->assertInertia(fn (Assert $page) => $page->has('clarifications', 1)->where('clarifications.0.status', 'open'));
});

test('answering adds a verified bullet point for the candidate and closes the gap after re-matching', function () {
    $candidate = candidateWithTwoFactor();
    $application = applicationWithGap($candidate);
    $request = clarificationsOf($candidate)[0];

    $this->actingAs($candidate)
        ->post(route('applications.clarifications.answer', [$application, $request]), [
            'answer' => 'Ran Kubernetes operations for 40 services at Contoso.',
            'subject' => 'general',
        ])
        ->assertInertiaFlash('toast.message', 'Added to your library. The evidence is matched again in a moment.');

    $request = clarificationsOf($candidate)[0];
    $claim = inWorkspaceOf($candidate, fn () => Claim::query()->with('currentRevision')->find($request->converted_claim_id));
    $latest = inWorkspaceOf($candidate, fn () => $application->latestAnalysis()->first());
    expect($request->status)->toBe('answered')
        ->and($claim->statement())->toBe('Ran Kubernetes operations for 40 services at Contoso.')
        ->and($claim->status)->toBe(ClaimStatus::Verified)
        ->and(collect($latest->matches['requirements'])->firstWhere('requirement', 'Kubernetes operations')['coverage'])->toBe('strong');
});

test('a VA\'s answer is a proposed bullet point for the candidate to verify', function () {
    $candidate = candidateWithTwoFactor();
    $application = applicationWithGap($candidate);

    $this->actingAs(vaOf($candidate))->post(route('applications.clarifications.answer', [$application, clarificationsOf($candidate)[0]]), [
        'answer' => 'Operated Kubernetes clusters at Contoso.',
    ]);

    $claim = inWorkspaceOf($candidate, fn () => Claim::query()->find(clarificationsOf($candidate)[0]->converted_claim_id));
    expect($claim->status)->toBe(ClaimStatus::Proposed);
});

test('a dismissed gap is not asked again when the job is re-analysed', function () {
    $candidate = candidateWithTwoFactor();
    $application = applicationWithGap($candidate);

    $this->actingAs($candidate)->post(route('applications.clarifications.dismiss', [$application, clarificationsOf($candidate)[0]]));
    expect(clarificationsOf($candidate)[0]->status)->toBe('dismissed');

    app(LlmRouter::class)->fake(new FakeLlmClient([[
        'summary' => 'Platform role.', 'seniority' => 'senior',
        'must_haves' => [['requirement' => 'Kubernetes operations', 'quote' => 'run Kubernetes clusters']],
        'nice_to_haves' => [], 'employer_language' => [], 'questions' => [], 'red_flags' => [], 'injection_warnings' => [],
    ]]));
    $this->actingAs($candidate)->post(route('applications.analysis.store', $application));

    expect(clarificationsOf($candidate))->toHaveCount(1);
});

test('an open gap resolves itself when evidence appears and the evidence is refreshed', function () {
    $candidate = candidateWithTwoFactor();
    $application = applicationWithGap($candidate);
    claimOf($candidate, 'Ran Kubernetes operations for every environment');

    $latest = inWorkspaceOf($candidate, fn () => $application->latestAnalysis()->first());
    $this->actingAs($candidate)->post(route('applications.analysis.refresh', [$application, $latest]));

    expect(clarificationsOf($candidate)[0]->status)->toBe('resolved');
});

test('answers are validated and a gap of another application cannot be answered through this one', function () {
    $candidate = candidateWithTwoFactor();
    $application = applicationWithGap($candidate);
    $request = clarificationsOf($candidate)[0];
    $other = JobApplication::factory()->forProfile(profileOf($candidate))->create();

    $this->actingAs($candidate)
        ->post(route('applications.clarifications.answer', [$application, $request]), ['answer' => '  ', 'subject' => 'project:missing'])
        ->assertSessionHasErrors('answer');
    $this->actingAs($candidate)
        ->post(route('applications.clarifications.answer', [$other, $request]), ['answer' => 'Something'])
        ->assertNotFound();
});
