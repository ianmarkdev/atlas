<?php

use App\Domain\Applications\ApplicationStage;
use App\Domain\Applications\ApplyRequirement;
use App\Domain\Applications\JobApplication;
use App\Domain\Applications\JobPosting;
use App\Domain\Audit\AuditEvent;
use App\Models\User;
use Illuminate\Database\QueryException;
use Inertia\Testing\AssertableInertia as Assert;

function applicationOf(User $candidate, array $attributes = [], ?string $jobDescription = null): JobApplication
{
    $factory = JobApplication::factory()->forProfile(profileOf($candidate));

    return ($jobDescription === null ? $factory : $factory->withJobDescription($jobDescription))->create($attributes);
}

function onlyApplication(User $candidate): JobApplication
{
    return inWorkspaceOf($candidate, fn () => JobApplication::withTrashed()->with('posting')->sole());
}

/**
 * @return array<string, mixed>
 */
function applicationForm(array $overrides = []): array
{
    return [
        'company_name' => 'Northwind Payments',
        'role_title' => 'Senior Backend Engineer',
        'source_url' => 'https://jobs.example.com/123',
        'location' => 'London (hybrid)',
        'employment_type' => 'full_time',
        'salary_text' => '£85k',
        'deadline_on' => '2026-11-15',
        'notes' => 'Referred by Sam.',
        'source' => 'linkedin.com',
        'subject_line' => 'Backend application',
        'apply_requirement' => 'video_and_test',
        'proposal_angle' => 'Lead with payments.',
        'job_description' => "We are hiring.\n\nRequirements:\n- PHP",
        'stage' => 'saved',
        ...$overrides,
    ];
}

describe('create', function () {
    test('adding an application stores the details and the job description verbatim', function () {
        $candidate = candidateWithTwoFactor();

        $response = $this->actingAs($candidate)->post(route('applications.store'), applicationForm());

        $application = onlyApplication($candidate);
        $response->assertRedirect(route('applications.show', $application));
        expect($application->company_name)->toBe('Northwind Payments')
            ->and($application->stage)->toBe(ApplicationStage::Saved)
            ->and($application->deadline_on?->format('Y-m-d'))->toBe('2026-11-15')
            ->and($application->source)->toBe('linkedin.com')
            ->and($application->subject_line)->toBe('Backend application')
            ->and($application->apply_requirement)->toBe(ApplyRequirement::VideoAndTest)
            ->and($application->proposal_angle)->toBe('Lead with payments.')
            ->and($application->owner_user_id)->toBe($candidate->id)
            ->and($application->applied_at)->toBeNull()
            ->and($application->jobDescription())->toBe("We are hiring.\n\nRequirements:\n- PHP")
            ->and($application->posting?->raw_sha256)->toBe(hash('sha256', "We are hiring.\n\nRequirements:\n- PHP"))
            ->and(inWorkspaceOf($candidate, fn () => AuditEvent::query()->where('action', 'application.created')->count()))->toBe(1);
    });

    test('a VA adds applications too', function () {
        $candidate = candidateWithTwoFactor();

        $this->actingAs(vaOf($candidate))->post(route('applications.store'), applicationForm(['job_description' => null]))
            ->assertRedirect();

        expect(onlyApplication($candidate)->posting)->toBeNull();
    });

    test('adding one that was already sent records when', function () {
        $candidate = candidateWithTwoFactor();

        $this->actingAs($candidate)->post(route('applications.store'), applicationForm(['stage' => 'applied']));

        expect(onlyApplication($candidate)->applied_at)->not->toBeNull();
    });

    test('company and role are required, the link must be a web address and dates real', function () {
        $this->actingAs(candidateWithTwoFactor())
            ->post(route('applications.store'), applicationForm([
                'company_name' => '  ',
                'role_title' => '',
                'source_url' => 'javascript:alert(1)',
                'deadline_on' => '2026-02-30',
                'apply_requirement' => 'essay',
            ]))
            ->assertSessionHasErrors(['company_name', 'role_title', 'source_url', 'deadline_on', 'apply_requirement']);
    });

    test('a job description over 50,000 characters is rejected', function () {
        $this->actingAs(candidateWithTwoFactor())
            ->post(route('applications.store'), applicationForm(['job_description' => str_repeat('a', 50001)]))
            ->assertSessionHasErrors('job_description');
    });
});

describe('edit', function () {
    test('a corrected job description is stored next to the earlier one', function () {
        $candidate = candidateWithTwoFactor();
        $application = applicationOf($candidate, [], 'First paste');

        $this->actingAs($candidate)
            ->put(route('applications.update', $application), applicationForm(['job_description' => 'Full paste', 'lock_version' => 0]))
            ->assertRedirect(route('applications.show', $application));

        $postings = inWorkspaceOf($candidate, fn () => JobPosting::query()->orderBy('id')->pluck('raw_text')->all());
        expect($postings)->toBe(['First paste', 'Full paste'])
            ->and(onlyApplication($candidate)->jobDescription())->toBe('Full paste')
            ->and(onlyApplication($candidate)->lock_version)->toBe(1);
    });

    test('saving the same job description again does not add a version', function () {
        $candidate = candidateWithTwoFactor();
        $application = applicationOf($candidate, [], 'Same text');

        $this->actingAs($candidate)->put(route('applications.update', $application), applicationForm(['job_description' => 'Same text', 'lock_version' => 0]));

        expect(inWorkspaceOf($candidate, fn () => JobPosting::query()->count()))->toBe(1);
    });

    test('an edit based on an outdated version is rejected', function () {
        $candidate = candidateWithTwoFactor();
        $application = applicationOf($candidate, ['lock_version' => 2, 'company_name' => 'Old']);

        $this->actingAs($candidate)
            ->put(route('applications.update', $application), applicationForm(['lock_version' => 1]))
            ->assertSessionHasErrors('lock_version');

        expect(onlyApplication($candidate)->company_name)->toBe('Old');
    });

    test('job descriptions cannot be edited once stored', function () {
        $candidate = candidateWithTwoFactor();
        applicationOf($candidate, [], 'Original');

        inWorkspaceOf($candidate, fn () => JobPosting::query()->update(['raw_text' => 'Tampered']));
    })->throws(QueryException::class, 'append-only');
});

describe('stage', function () {
    test('moving to applied records the date once; moving on keeps it', function () {
        $candidate = candidateWithTwoFactor();
        $application = applicationOf($candidate);

        $this->actingAs($candidate)->patch(route('applications.stage', $application), ['stage' => 'applied'])->assertRedirect();
        $appliedAt = onlyApplication($candidate)->applied_at;

        $this->travel(2)->days();
        $this->actingAs(vaOf($candidate))->patch(route('applications.stage', $application), ['stage' => 'interviewing']);

        $application = onlyApplication($candidate);
        $event = inWorkspaceOf($candidate, fn () => AuditEvent::query()->where('action', 'application.stage_changed')->latest('id')->first());
        expect($application->stage)->toBe(ApplicationStage::Interviewing)
            ->and($appliedAt)->not->toBeNull()
            ->and($application->applied_at?->equalTo($appliedAt))->toBeTrue()
            ->and($event?->metadata)->toEqual(['from' => 'applied', 'to' => 'interviewing']);
    });

    test('an unknown stage is rejected', function () {
        $candidate = candidateWithTwoFactor();

        $this->actingAs($candidate)
            ->patch(route('applications.stage', applicationOf($candidate)), ['stage' => 'hired'])
            ->assertSessionHasErrors('stage');
    });
});

describe('delete and restore', function () {
    test('the candidate deletes and restores an application; a VA cannot', function () {
        $candidate = candidateWithTwoFactor();
        $va = vaOf($candidate);
        $application = applicationOf($candidate);

        $this->actingAs($va)->delete(route('applications.destroy', $application))->assertForbidden();
        $this->actingAs($candidate)->delete(route('applications.destroy', $application))->assertRedirect(route('applications.index'));
        expect(onlyApplication($candidate)->trashed())->toBeTrue();

        $this->actingAs($candidate)->get(route('applications.show', $application))->assertNotFound();
        $this->actingAs($va)->patch(route('applications.restore', $application))->assertForbidden();
        $this->actingAs($candidate)->patch(route('applications.restore', $application))->assertRedirect(route('applications.show', $application));
        expect(onlyApplication($candidate)->trashed())->toBeFalse();
    });

    test('only the candidate can list recently deleted applications', function () {
        $candidate = candidateWithTwoFactor();
        applicationOf($candidate, ['company_name' => 'Kept']);
        applicationOf($candidate, ['company_name' => 'Gone'])->delete();

        $this->actingAs($candidate)->get(route('applications.index', ['deleted' => 1]))
            ->assertInertia(fn (Assert $page) => $page->has('applications', 1)->where('applications.0.company_name', 'Gone'));

        $this->actingAs(vaOf($candidate))->get(route('applications.index', ['deleted' => 1]))
            ->assertInertia(fn (Assert $page) => $page->where('filters.deleted', false)->where('applications.0.company_name', 'Kept'));
    });
});

test('the list searches company and role, newest first, and flags overdue ones', function () {
    $candidate = candidateWithTwoFactor();
    applicationOf($candidate, ['company_name' => 'Later Ltd', 'deadline_on' => now()->addDays(10), 'created_at' => now()->subDays(2)]);
    applicationOf($candidate, ['company_name' => 'Contoso', 'role_title' => 'Ruby Developer']);
    applicationOf($candidate, ['company_name' => 'Late Co', 'deadline_on' => now()->subDay(), 'created_at' => now()->subDay()]);

    $this->actingAs($candidate)->get(route('applications.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('applications/index')
            ->where('applications.0.company_name', 'Contoso')
            ->where('applications.1.company_name', 'Late Co')
            ->where('applications.1.is_overdue', true)
            ->where('applications.2.company_name', 'Later Ltd')
            ->where('applications.2.is_overdue', false));

    $this->actingAs($candidate)->get(route('applications.index', ['q' => 'ruby']))
        ->assertInertia(fn (Assert $page) => $page->has('applications', 1)->where('applications.0.company_name', 'Contoso'));
});

test('the application page links the applications before and after it in the list', function () {
    $candidate = candidateWithTwoFactor();
    $last = applicationOf($candidate, ['company_name' => 'Contoso', 'role_title' => 'Ruby Developer', 'created_at' => now()->subDays(2)]);
    $middle = applicationOf($candidate, ['company_name' => 'Later Ltd', 'created_at' => now()->subDay()]);
    $first = applicationOf($candidate, ['company_name' => 'Late Co', 'role_title' => 'Engineer']);

    $this->actingAs($candidate)->get(route('applications.show', $middle))
        ->assertInertia(fn (Assert $page) => $page
            ->where('neighbours.position', 2)
            ->where('neighbours.total', 3)
            ->where('neighbours.previous', ['ulid' => $first->ulid, 'label' => 'Engineer · Late Co'])
            ->where('neighbours.next', ['ulid' => $last->ulid, 'label' => 'Ruby Developer · Contoso']));

    $this->actingAs($candidate)->get(route('applications.show', $first))
        ->assertInertia(fn (Assert $page) => $page->where('neighbours.previous', null)->where('neighbours.next.ulid', $middle->ulid));
});

test('an application of another workspace responds 404', function () {
    $foreign = applicationOf(candidateWithTwoFactor());
    $candidate = candidateWithTwoFactor();

    $this->actingAs($candidate)->get(route('applications.show', $foreign))->assertNotFound();
    $this->actingAs($candidate)->patch(route('applications.stage', $foreign), ['stage' => 'applied'])->assertNotFound();
});

test('the dashboard counts applications per stage and lists deadline work', function () {
    $candidate = candidateWithTwoFactor();
    applicationOf($candidate, ['deadline_on' => now()->subDay()]);
    applicationOf($candidate, ['deadline_on' => now()->addDays(2), 'stage' => ApplicationStage::Preparing]);
    JobApplication::factory()->forProfile(profileOf($candidate))->inStage(ApplicationStage::Applied)->create(['deadline_on' => now()->subDays(5)]);

    $this->actingAs($candidate)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('pipeline.saved', 1)
            ->where('pipeline.preparing', 1)
            ->where('pipeline.applied', 1)
            ->where('pipeline.offer', 0)
            ->where('attention.0.key', 'overdue')
            ->where('attention.0.count', 1)
            ->where('attention.1.key', 'closing')
            ->where('attention.1.count', 1));
});
