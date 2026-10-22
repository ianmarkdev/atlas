<?php

use App\Domain\Applications\ApplicationResume;
use App\Domain\Applications\ApplicationResumeVersion;
use App\Domain\Applications\JobApplication;
use App\Domain\Knowledge\Claim;
use App\Domain\Knowledge\ClaimStatus;
use App\Domain\Knowledge\Employment;
use App\Domain\Knowledge\KnowledgeProposal;
use App\Domain\Knowledge\ProposalStatus;
use App\Domain\Resumes\ResumeVariant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A candidate with one job, one verified bullet, a resume variant showing both, and an application.
 *
 * @return array{candidate: User, employment: Employment, claim: Claim, variant: ResumeVariant, application: JobApplication}
 */
function tailoringSetup(): array
{
    $candidate = candidateWithTwoFactor();
    $employment = employmentOf($candidate);
    $claim = claimOf($candidate, 'Cut the invoice run to 20 minutes', $employment);
    $variant = ResumeVariant::factory()->forProfile(profileOf($candidate))->withDocument([
        'title' => 'Senior PHP Engineer',
        'experience' => [tailoredJob($employment, [['claim' => $claim->ulid, 'text' => null]])],
    ])->create(['name' => 'PHP focused']);
    $application = JobApplication::factory()->forProfile(profileOf($candidate))->create();

    return compact('candidate', 'employment', 'claim', 'variant', 'application');
}

/**
 * @param  list<array{claim: string, text: ?string}>  $bullets
 * @return array<string, mixed>
 */
function tailoredJob(Employment $employment, array $bullets = [], ?string $displayTitle = null): array
{
    return ['employment' => $employment->ulid, 'display_title' => $displayTitle, 'description' => null, 'bullets' => $bullets, 'projects' => []];
}

function tailoredResumeOf(User $candidate): ApplicationResume
{
    return inWorkspaceOf($candidate, fn () => ApplicationResume::query()->with(['currentVersion', 'approvedVersion'])->sole());
}

/**
 * @param  array<string, mixed>  $document
 * @return array<string, mixed>
 */
function tailoredSave(ApplicationResume $resume, array $document): array
{
    return [
        'document' => ['schema_version' => 1, 'title' => null, 'summary' => null, 'experience' => [], 'projects' => [], ...$document],
        'lock_version' => $resume->lock_version,
    ];
}

describe('start', function () {
    test('starting from a resume copies its document into version 1 with a snapshot of the facts', function () {
        ['candidate' => $candidate, 'employment' => $employment, 'claim' => $claim, 'variant' => $variant, 'application' => $application] = tailoringSetup();

        $this->actingAs($candidate)
            ->post(route('applications.resume.store', $application), ['variant' => $variant->ulid])
            ->assertRedirect(route('applications.resume.show', $application));

        $resume = tailoredResumeOf($candidate);
        $snapshot = $resume->currentVersion?->facts_snapshot;
        expect($resume->status)->toBe('draft')
            ->and($resume->based_on_variant_id)->toBe($variant->id)
            ->and($resume->currentVersion?->version_no)->toBe(1)
            ->and($resume->currentVersion?->created_via)->toBe("copied:{$variant->id}")
            ->and($resume->document()['title'])->toBe('Senior PHP Engineer')
            ->and($snapshot['library']['employments'][0]['employer_name'])->toBe($employment->employer_name)
            ->and(array_column($snapshot['library']['employments'][0]['claims'], 'statement'))->toBe(['Cut the invoice run to 20 minutes'])
            ->and($snapshot['contact']['display_name'])->toBe(profileOf($candidate)->display_name);
        expect(collect($snapshot['library']['employments'][0]['claims'])->pluck('ulid')->all())->toBe([$claim->ulid]);
    });

    test('the snapshot leaves out library items the resume does not show', function () {
        ['candidate' => $candidate, 'variant' => $variant, 'application' => $application] = tailoringSetup();
        $other = employmentOf($candidate);
        claimOf($candidate, 'Not on this resume', $other);

        $this->actingAs($candidate)->post(route('applications.resume.store', $application), ['variant' => $variant->ulid]);

        $employments = tailoredResumeOf($candidate)->currentVersion?->facts_snapshot['library']['employments'];
        expect(array_column($employments, 'ulid'))->not->toContain($other->ulid);
    });

    test('an application gets one tailored resume', function () {
        ['candidate' => $candidate, 'variant' => $variant, 'application' => $application] = tailoringSetup();

        $this->actingAs($candidate)->post(route('applications.resume.store', $application), ['variant' => $variant->ulid]);
        $this->actingAs($candidate)
            ->post(route('applications.resume.store', $application), ['variant' => $variant->ulid])
            ->assertSessionHasErrors('resume');

        expect(inWorkspaceOf($candidate, fn () => ApplicationResume::query()->count()))->toBe(1);
    });

    test('a resume of another workspace cannot be copied', function () {
        ['candidate' => $candidate, 'application' => $application] = tailoringSetup();
        $foreign = tailoringSetup()['variant'];

        $this->actingAs($candidate)
            ->post(route('applications.resume.store', $application), ['variant' => $foreign->ulid])
            ->assertSessionHasErrors('variant');
    });
});

describe('tailor', function () {
    test('a VA tailors the resume; each save is a new version and the variant is untouched', function () {
        ['candidate' => $candidate, 'employment' => $employment, 'claim' => $claim, 'variant' => $variant, 'application' => $application] = tailoringSetup();
        $this->actingAs($candidate)->post(route('applications.resume.store', $application), ['variant' => $variant->ulid]);
        $resume = tailoredResumeOf($candidate);

        $this->actingAs(vaOf($candidate))
            ->put(route('applications.resume.update', $application), tailoredSave($resume, [
                'title' => 'Senior Backend Engineer, Payments',
                'experience' => [tailoredJob($employment, [['claim' => $claim->ulid, 'text' => 'Cut the nightly billing run from 4 hours to 20 minutes']])],
            ]))
            ->assertRedirect(route('applications.resume.show', $application));

        $resume = tailoredResumeOf($candidate);
        expect($resume->currentVersion?->version_no)->toBe(2)
            ->and($resume->document()['title'])->toBe('Senior Backend Engineer, Payments')
            ->and($resume->document()['experience'][0]['bullets'][0]['text'])->toBe('Cut the nightly billing run from 4 hours to 20 minutes')
            ->and($variant->refresh()->load('currentRevision')->document()['title'])->toBe('Senior PHP Engineer')
            ->and($claim->refresh()->load('currentRevision')->statement())->toBe('Cut the invoice run to 20 minutes');
    });

    test('only the candidate changes how a job title is shown', function () {
        ['candidate' => $candidate, 'employment' => $employment, 'variant' => $variant, 'application' => $application] = tailoringSetup();
        $this->actingAs($candidate)->post(route('applications.resume.store', $application), ['variant' => $variant->ulid]);
        $resume = tailoredResumeOf($candidate);
        $payload = tailoredSave($resume, ['experience' => [tailoredJob($employment, [], 'Lead Payments Engineer')]]);

        $this->actingAs(vaOf($candidate))
            ->put(route('applications.resume.update', $application), $payload)
            ->assertSessionHasErrors('document');
        $this->actingAs($candidate)
            ->put(route('applications.resume.update', $application), $payload)
            ->assertSessionHasNoErrors();

        expect(tailoredResumeOf($candidate)->document()['experience'][0]['display_title'])->toBe('Lead Payments Engineer');
    });

    test('a save based on an outdated version is rejected', function () {
        ['candidate' => $candidate, 'variant' => $variant, 'application' => $application] = tailoringSetup();
        $this->actingAs($candidate)->post(route('applications.resume.store', $application), ['variant' => $variant->ulid]);
        $resume = tailoredResumeOf($candidate);
        $this->actingAs($candidate)->put(route('applications.resume.update', $application), tailoredSave($resume, ['title' => 'First']));

        $this->actingAs($candidate)
            ->put(route('applications.resume.update', $application), tailoredSave($resume, ['title' => 'Stale']))
            ->assertSessionHasErrors('lock_version');
    });

    test('the document is checked against the library like any resume', function () {
        ['candidate' => $candidate, 'variant' => $variant, 'application' => $application] = tailoringSetup();
        $foreignJob = employmentOf(candidateWithTwoFactor());
        $this->actingAs($candidate)->post(route('applications.resume.store', $application), ['variant' => $variant->ulid]);

        $this->actingAs($candidate)
            ->put(route('applications.resume.update', $application), tailoredSave(tailoredResumeOf($candidate), ['experience' => [tailoredJob($foreignJob)]]))
            ->assertSessionHasErrors('document.experience.0.employment');
    });

    test('starting over copies a resume again as a new version', function () {
        ['candidate' => $candidate, 'variant' => $variant, 'application' => $application] = tailoringSetup();
        $other = ResumeVariant::factory()->forProfile(profileOf($candidate))->withDocument(['title' => 'Ruby Engineer'])->create(['name' => 'Ruby focused']);
        $this->actingAs($candidate)->post(route('applications.resume.store', $application), ['variant' => $variant->ulid]);

        $this->actingAs($candidate)->post(route('applications.resume.copy', $application), ['variant' => $other->ulid, 'lock_version' => 0]);

        $resume = tailoredResumeOf($candidate);
        expect($resume->currentVersion?->version_no)->toBe(2)
            ->and($resume->based_on_variant_id)->toBe($other->id)
            ->and($resume->document()['title'])->toBe('Ruby Engineer');
    });
});

describe('approve', function () {
    test('approving freezes the resume: saves are refused and the page shows the approved facts', function () {
        ['candidate' => $candidate, 'employment' => $employment, 'variant' => $variant, 'application' => $application] = tailoringSetup();
        $this->actingAs($candidate)->post(route('applications.resume.store', $application), ['variant' => $variant->ulid]);

        $this->actingAs(vaOf($candidate))->patch(route('applications.resume.approve', $application))->assertForbidden();
        $this->actingAs($candidate)->patch(route('applications.resume.approve', $application))->assertSessionHasNoErrors();

        $resume = tailoredResumeOf($candidate);
        expect($resume->status)->toBe('approved')
            ->and($resume->approved_version_id)->toBe($resume->current_version_id)
            ->and($resume->approved_by)->toBe($candidate->id);

        $this->actingAs($candidate)
            ->put(route('applications.resume.update', $application), tailoredSave($resume, ['title' => 'Changed']))
            ->assertForbidden();

        // The library changes afterwards; the approved resume still shows what was approved.
        $approvedName = $employment->employer_name;
        $employment->forceFill(['employer_name' => 'Renamed Ltd'])->save();

        $this->actingAs(vaOf($candidate))->get(route('applications.resume.show', $application))
            ->assertInertia(fn (Assert $page) => $page
                ->component('applications/resume')
                ->where('resume.status', 'approved')
                ->where('library.employments.0.employer_name', $approvedName)
                ->where('can.update', false)
                ->where('can.reopen', false));
    });

    test('a resume showing unverified facts cannot be approved', function () {
        ['candidate' => $candidate, 'employment' => $employment, 'claim' => $claim, 'variant' => $variant, 'application' => $application] = tailoringSetup();
        $proposed = Claim::factory()->forProfile(profileOf($candidate))->about($employment)->stating('Proposed by the VA')->create();
        $this->actingAs($candidate)->post(route('applications.resume.store', $application), ['variant' => $variant->ulid]);
        $this->actingAs($candidate)->put(route('applications.resume.update', $application), tailoredSave(tailoredResumeOf($candidate), [
            'experience' => [tailoredJob($employment, [['claim' => $claim->ulid, 'text' => null], ['claim' => $proposed->ulid, 'text' => null]])],
        ]));

        $this->actingAs($candidate)
            ->patch(route('applications.resume.approve', $application))
            ->assertSessionHasErrors(['resume' => 'Verify these in your library before approving: Proposed by the VA']);

        expect(tailoredResumeOf($candidate)->status)->toBe('draft');
    });

    test('the candidate reopens an approved resume to edit it again', function () {
        ['candidate' => $candidate, 'variant' => $variant, 'application' => $application] = tailoringSetup();
        $this->actingAs($candidate)->post(route('applications.resume.store', $application), ['variant' => $variant->ulid]);
        $this->actingAs($candidate)->patch(route('applications.resume.approve', $application));

        $this->actingAs(vaOf($candidate))->patch(route('applications.resume.reopen', $application))->assertForbidden();
        $this->actingAs($candidate)->patch(route('applications.resume.reopen', $application));
        $resume = tailoredResumeOf($candidate);

        $this->actingAs($candidate)
            ->put(route('applications.resume.update', $application), tailoredSave($resume, ['title' => 'Edited again']))
            ->assertSessionHasNoErrors();

        $resume = tailoredResumeOf($candidate);
        expect($resume->status)->toBe('draft')
            ->and($resume->approved_version_id)->not->toBe($resume->current_version_id);
    });
});

test('the candidate saves the tailored content back to the resume it came from', function () {
    ['candidate' => $candidate, 'variant' => $variant, 'application' => $application] = tailoringSetup();
    $this->actingAs($candidate)->post(route('applications.resume.store', $application), ['variant' => $variant->ulid]);
    $this->actingAs($candidate)->put(route('applications.resume.update', $application), tailoredSave(tailoredResumeOf($candidate), ['title' => 'Better title']));

    $this->actingAs(vaOf($candidate))->post(route('applications.resume.save-back', $application))->assertForbidden();
    $this->actingAs($candidate)->post(route('applications.resume.save-back', $application))->assertSessionHasNoErrors();

    $variant->refresh()->load('currentRevision');
    expect($variant->document()['title'])->toBe('Better title')
        ->and($variant->currentRevision?->created_via)->toStartWith('from_application:');
});

test('tailored resume versions cannot be edited once written', function () {
    ['candidate' => $candidate, 'variant' => $variant, 'application' => $application] = tailoringSetup();
    $this->actingAs($candidate)->post(route('applications.resume.store', $application), ['variant' => $variant->ulid]);

    inWorkspaceOf($candidate, fn () => ApplicationResumeVersion::query()->update(['document' => '{}']));
})->throws(QueryException::class, 'append-only');

test('the tailored resume of another workspace responds 404', function () {
    ['candidate' => $owner, 'variant' => $variant, 'application' => $application] = tailoringSetup();
    $this->actingAs($owner)->post(route('applications.resume.store', $application), ['variant' => $variant->ulid]);

    $this->actingAs(candidateWithTwoFactor())->get(route('applications.resume.show', $application))->assertNotFound();
});

test('the application page summarises its tailored resume', function () {
    ['candidate' => $candidate, 'variant' => $variant, 'application' => $application] = tailoringSetup();

    $this->actingAs($candidate)->get(route('applications.show', $application))
        ->assertInertia(fn (Assert $page) => $page->where('tailoredResume', null)->where('variants.0.label', 'PHP focused'));

    $this->actingAs($candidate)->post(route('applications.resume.store', $application), ['variant' => $variant->ulid]);

    $this->actingAs($candidate)->get(route('applications.show', $application))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tailoredResume.status', 'draft')
            ->where('tailoredResume.based_on', 'PHP focused')
            ->where('tailoredResume.jobs', 1));
});

describe('learning from the tailored resume', function () {
    test("a VA's rewording of a verified bullet point is suggested to the candidate, not applied", function () {
        ['candidate' => $candidate, 'claim' => $claim, 'variant' => $variant, 'application' => $application] = tailoringSetup();
        $this->actingAs($candidate)->post(route('applications.resume.store', $application), ['variant' => $variant->ulid]);
        $va = vaOf($candidate);

        $this->actingAs($va)
            ->postJson(route('applications.resume.apply-bullet', [$application, $claim]), ['text' => 'Cut the monthly invoice run to 20 minutes'])
            ->assertOk()
            ->assertJsonPath('proposed', true)
            ->assertJsonPath('claim.statement', 'Cut the invoice run to 20 minutes');

        $proposal = inWorkspaceOf($candidate, fn () => KnowledgeProposal::query()->sole());
        expect($proposal->proposal_type)->toBe('revise_claim')
            ->and($proposal->payload['statement'])->toBe('Cut the monthly invoice run to 20 minutes')
            ->and($proposal->proposed_by_user_id)->toBe($va->id);
    });

    test('a rewording saved as a new bullet point is about the same job, and proposed when a VA saves it', function () {
        ['candidate' => $candidate, 'claim' => $claim, 'employment' => $employment, 'variant' => $variant, 'application' => $application] = tailoringSetup();
        $this->actingAs($candidate)->post(route('applications.resume.store', $application), ['variant' => $variant->ulid]);

        $this->actingAs(vaOf($candidate))
            ->postJson(route('applications.resume.new-bullet', [$application, $claim]), ['text' => 'Automated the invoice run'])
            ->assertOk()
            ->assertJsonPath('claim.statement', 'Automated the invoice run');

        $created = inWorkspaceOf($candidate, fn () => Claim::query()->whereKeyNot($claim->id)->sole());
        expect($created->status)->toBe(ClaimStatus::Proposed)
            ->and($created->subject_id)->toBe($employment->id)
            ->and(inWorkspaceOf($candidate, fn () => $claim->fresh('currentRevision'))->statement())->toBe('Cut the invoice run to 20 minutes');
    });

    test('the tailored title and summary go to the variant: directly for the candidate, as a suggestion from a VA', function () {
        ['candidate' => $candidate, 'variant' => $variant, 'application' => $application] = tailoringSetup();
        $this->actingAs($candidate)->post(route('applications.resume.store', $application), ['variant' => $variant->ulid]);
        $this->actingAs($candidate)->put(route('applications.resume.update', $application), tailoredSave(tailoredResumeOf($candidate), ['title' => 'Billing Engineer', 'summary' => 'I build billing systems.']));

        $this->actingAs(vaOf($candidate))
            ->post(route('applications.resume.headline-to-variant', $application))
            ->assertInertiaFlash('toast.message', 'Suggested to the candidate: use this title and summary on "PHP focused".');

        $proposal = inWorkspaceOf($candidate, fn () => KnowledgeProposal::query()->sole());
        expect($proposal->proposal_type)->toBe('revise_resume_variant')
            ->and($variant->refresh()->document()['title'])->toBe('Senior PHP Engineer');

        $this->actingAs($candidate)->patch(route('knowledge.proposals.approve', $proposal))->assertSessionHasNoErrors();

        $variant->refresh()->load('currentRevision');
        expect($variant->document()['title'])->toBe('Billing Engineer')
            ->and($variant->document()['summary'])->toBe('I build billing systems.')
            ->and($variant->document()['experience'])->toHaveCount(1)
            ->and($variant->currentRevision->created_via)->toBe("proposal:{$proposal->id}")
            ->and($proposal->fresh()->status)->toBe(ProposalStatus::Approved);

        $this->actingAs($candidate)
            ->post(route('applications.resume.headline-to-variant', $application))
            ->assertSessionHasErrors(['resume' => '"PHP focused" already has this title and summary.']);
    });
});
