<?php

use App\Console\Commands\ExtensionToken;
use App\Domain\Applications\ApplicationStage;
use App\Domain\Applications\JobApplication;
use App\Domain\Audit\AuditEvent;
use App\Http\Requests\Applications\SaveJobApplicationRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

/**
 * @return array<string, mixed>
 */
function capturePayload(array $overrides = []): array
{
    return [
        'company_name' => 'Northwind Payments',
        'role_title' => 'Senior Backend Engineer',
        'source_url' => 'https://jobs.example.com/listing/123?utm_source=newsletter',
        'location' => 'London (hybrid)',
        'employment_type' => 'full_time',
        'salary_text' => 'GBP 70,000–85,000 / year',
        'deadline_on' => '2026-11-30',
        'job_description' => "We are hiring.\n\nRequirements:\n- PHP",
        'capture' => [
            'method' => 'json-ld',
            'site' => 'jobs.example.com',
            'external_id' => 'REQ-123',
            'posted_at' => '2026-10-01',
            'workplace_type' => 'hybrid',
            'salary' => ['min' => 70000, 'max' => 85000, 'currency' => 'GBP', 'period' => 'year'],
            'captured_at' => '2026-10-21T10:00:00Z',
        ],
        ...$overrides,
    ];
}

function captureAs(User $user, array $payload = []): TestResponse
{
    Sanctum::actingAs($user, [ExtensionToken::ABILITY]);

    return test()->postJson(route('api.extension.applications.store'), capturePayload($payload));
}

function capturedApplications(User $user): Collection
{
    return inWorkspaceOf($user, fn () => JobApplication::withTrashed()->with('posting')->orderBy('id')->get());
}

describe('saving a captured listing', function () {
    test('creates a saved application with the job description and records the capture', function () {
        $candidate = candidateWithTwoFactor();

        $response = captureAs($candidate);

        $application = capturedApplications($candidate)->sole();
        $response->assertCreated()->assertExactJson(['data' => [
            'ulid' => $application->ulid,
            'url' => route('applications.show', $application),
            'company_name' => 'Northwind Payments',
            'role_title' => 'Senior Backend Engineer',
            'stage' => 'saved',
            'deleted' => false,
        ]]);
        expect($application->stage)->toBe(ApplicationStage::Saved)
            ->and($application->owner_user_id)->toBe($candidate->id)
            ->and($application->location)->toBe('London (hybrid)')
            ->and($application->deadline_on?->format('Y-m-d'))->toBe('2026-11-30')
            ->and($application->notes)->toBeNull()
            ->and($application->jobDescription())->toBe("We are hiring.\n\nRequirements:\n- PHP")
            ->and($application->posting?->captured_by)->toBe($candidate->id);

        $event = inWorkspaceOf($candidate, fn () => AuditEvent::query()->where('action', 'application.captured')->sole());
        expect($event->metadata)->toMatchArray([
            'via' => 'browser_extension',
            'method' => 'json-ld',
            'site' => 'jobs.example.com',
            'external_id' => 'REQ-123',
            'workplace_type' => 'hybrid',
            'salary' => ['min' => 70000, 'max' => 85000, 'currency' => 'GBP', 'period' => 'year'],
            'duplicate_of' => null,
        ]);
    });

    test('only the title and company are required', function () {
        $candidate = candidateWithTwoFactor();

        captureAs($candidate, [
            'source_url' => null, 'location' => null, 'employment_type' => null, 'salary_text' => null,
            'deadline_on' => null, 'job_description' => null, 'capture' => null,
        ])->assertCreated();

        expect(capturedApplications($candidate)->sole()->posting)->toBeNull();
    });

    test('a VA can save listings too', function () {
        $va = vaOf(candidateWithTwoFactor());

        captureAs($va)->assertCreated();

        expect(capturedApplications($va)->sole()->owner_user_id)->toBe($va->id);
    });

    test('user-managed fields sent by the client are ignored', function () {
        $candidate = candidateWithTwoFactor();
        $other = candidateWithTwoFactor();

        captureAs($candidate, [
            'notes' => 'Injected', 'stage' => 'offer', 'owner_user_id' => $other->id,
            'workspace_id' => workspaceOf($other)->id, 'review_status' => 'approved',
        ])->assertCreated();

        $application = capturedApplications($candidate)->sole();
        expect($application->notes)->toBeNull()
            ->and($application->stage)->toBe(ApplicationStage::Saved)
            ->and($application->owner_user_id)->toBe($candidate->id)
            ->and($application->workspace_id)->toBe(workspaceOf($candidate)->id)
            ->and(capturedApplications($other))->toBeEmpty();
    });

    test('a long job description is stored in full', function () {
        $candidate = candidateWithTwoFactor();
        $description = str_repeat("Build reliable payment systems.\n", 1500);

        captureAs($candidate, ['job_description' => $description])->assertCreated();

        expect(capturedApplications($candidate)->sole()->jobDescription())->toBe(trim($description));
    });
});

describe('validation', function () {
    test('rejects invalid fields with field errors', function (array $payload, string $field) {
        $candidate = candidateWithTwoFactor();

        captureAs($candidate, $payload)->assertUnprocessable()->assertJsonValidationErrors([$field]);

        expect(capturedApplications($candidate))->toBeEmpty();
    })->with([
        'missing title' => [['role_title' => ''], 'role_title'],
        'missing company' => [['company_name' => '   '], 'company_name'],
        'non-http url' => [['source_url' => 'javascript:alert(1)'], 'source_url'],
        'unknown employment type' => [['employment_type' => 'gig'], 'employment_type'],
        'bad deadline' => [['deadline_on' => '30/11/2026'], 'deadline_on'],
        'unknown extraction method' => [['capture' => ['method' => 'magic']], 'capture.method'],
        'unknown workplace type' => [['capture' => ['workplace_type' => 'moon']], 'capture.workplace_type'],
        'salary max below min' => [['capture' => ['salary' => ['min' => 90000, 'max' => 50000]]], 'capture.salary.max'],
        'lowercase currency' => [['capture' => ['salary' => ['currency' => 'gbp']]], 'capture.salary.currency'],
        'unexpected capture key' => [['capture' => ['cookies' => 'x']], 'capture'],
    ]);

    test('rejects a job description over the limit instead of truncating it', function () {
        $candidate = candidateWithTwoFactor();

        captureAs($candidate, ['job_description' => str_repeat('a', SaveJobApplicationRequest::MAX_JOB_DESCRIPTION_LENGTH + 1)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['job_description']);
    });
});

describe('authentication', function () {
    test('requires a token', function () {
        $this->postJson(route('api.extension.applications.store'), capturePayload())->assertUnauthorized();
    });

    test('a session login is not accepted', function () {
        $candidate = candidateWithTwoFactor();

        $this->actingAs($candidate)->postJson(route('api.extension.applications.store'), capturePayload())->assertUnauthorized();

        expect(capturedApplications($candidate))->toBeEmpty();
    });

    test('a real extension token works and an unknown one does not', function () {
        $candidate = candidateWithTwoFactor();
        $token = $candidate->createToken('Career Clipper', [ExtensionToken::ABILITY])->plainTextToken;

        $this->withToken('1|not-a-real-token')->postJson(route('api.extension.applications.store'), capturePayload())->assertUnauthorized();
        $this->withToken($token)->postJson(route('api.extension.applications.store'), capturePayload())->assertCreated();
    });

    test('an expired token is refused', function () {
        $candidate = candidateWithTwoFactor();
        $token = $candidate->createToken('Career Clipper', [ExtensionToken::ABILITY], now()->subMinute())->plainTextToken;

        $this->withToken($token)->postJson(route('api.extension.applications.store'), capturePayload())->assertUnauthorized();
    });

    test('a token without the capture ability is forbidden', function () {
        $candidate = candidateWithTwoFactor();
        Sanctum::actingAs($candidate, ['something-else']);

        $this->postJson(route('api.extension.applications.store'), capturePayload())->assertForbidden();
    });

    test('a deactivated account is forbidden', function () {
        $va = vaOf(candidateWithTwoFactor());
        $va->forceFill(['is_active' => false])->save();

        captureAs($va)->assertForbidden()->assertJson(['message' => 'This account has been deactivated.']);
    });

    test('a candidate without two-factor authentication is forbidden', function () {
        $candidate = User::factory()->candidate()->create();

        captureAs($candidate)->assertForbidden()->assertJson(['message' => 'Set up two-factor authentication to continue.']);
    });

    test('saving is rate limited', function () {
        $candidate = candidateWithTwoFactor();
        Sanctum::actingAs($candidate, [ExtensionToken::ABILITY]);

        foreach (range(1, 30) as $_) {
            $this->postJson(route('api.extension.applications.store'), [])->assertUnprocessable();
        }

        $this->postJson(route('api.extension.applications.store'), [])->assertTooManyRequests();
    });
});

describe('duplicates', function () {
    test('the same listing is refused and the saved application is left untouched', function () {
        $candidate = candidateWithTwoFactor();
        $existing = JobApplication::factory()->forProfile(profileOf($candidate))->inStage(ApplicationStage::Interviewing)->create([
            'source_url' => 'https://www.jobs.example.com/listing/123/#apply',
            'notes' => 'Spoke to the recruiter.',
            'role_title' => 'Backend Engineer',
        ]);

        captureAs($candidate, ['role_title' => 'Renamed'])
            ->assertConflict()
            ->assertJson([
                'message' => 'This job listing has already been saved.',
                'existing' => ['ulid' => $existing->ulid, 'role_title' => 'Backend Engineer', 'stage' => 'interviewing', 'deleted' => false],
            ]);

        $after = capturedApplications($candidate)->sole();
        expect($after->notes)->toBe('Spoke to the recruiter.')
            ->and($after->role_title)->toBe('Backend Engineer')
            ->and($after->stage)->toBe(ApplicationStage::Interviewing)
            ->and($after->lock_version)->toBe(0);
    });

    test('a deleted application still counts, and says so', function () {
        $candidate = candidateWithTwoFactor();
        $existing = JobApplication::factory()->forProfile(profileOf($candidate))->create(['source_url' => 'https://jobs.example.com/listing/123']);
        $existing->delete();

        captureAs($candidate)->assertConflict()->assertJson(['existing' => ['ulid' => $existing->ulid, 'deleted' => true]]);
    });

    test('allow_duplicate saves a second application and links it to the first', function () {
        $candidate = candidateWithTwoFactor();
        $existing = JobApplication::factory()->forProfile(profileOf($candidate))->create(['source_url' => 'https://jobs.example.com/listing/123']);

        captureAs($candidate, ['allow_duplicate' => true])->assertCreated();

        expect(capturedApplications($candidate))->toHaveCount(2)
            ->and(inWorkspaceOf($candidate, fn () => AuditEvent::query()->where('action', 'application.captured')->sole()->metadata['duplicate_of']))->toBe($existing->ulid);
    });

    test('listings that differ in a meaningful query parameter are not duplicates', function () {
        $candidate = candidateWithTwoFactor();
        JobApplication::factory()->forProfile(profileOf($candidate))->create(['source_url' => 'https://jobs.example.com/view?jk=111']);

        captureAs($candidate, ['source_url' => 'https://jobs.example.com/view?jk=222'])->assertCreated();
    });

    test('another workspace\'s application is not a duplicate', function () {
        $candidate = candidateWithTwoFactor();
        $other = candidateWithTwoFactor();
        JobApplication::factory()->forProfile(profileOf($other))->create(['source_url' => 'https://jobs.example.com/listing/123']);

        captureAs($candidate)->assertCreated();
    });

    test('saving twice in a row creates one application', function () {
        $candidate = candidateWithTwoFactor();

        captureAs($candidate)->assertCreated();
        captureAs($candidate)->assertConflict();

        expect(capturedApplications($candidate))->toHaveCount(1);
    });
});

describe('app:extension-token', function () {
    test('issues a token that can only capture applications, and revokes it', function () {
        $candidate = candidateWithTwoFactor();

        $this->artisan('app:extension-token', ['email' => $candidate->email, '--days' => 30])->assertSuccessful();

        $token = PersonalAccessToken::query()->sole();
        expect($token->abilities)->toBe([ExtensionToken::ABILITY])
            ->and($token->tokenable_id)->toBe($candidate->id)
            ->and($token->expires_at?->isSameDay(now()->addDays(30)))->toBeTrue();

        $this->artisan('app:extension-token', ['email' => $candidate->email, '--revoke' => true])->assertSuccessful();

        expect(PersonalAccessToken::query()->count())->toBe(0);
    });

    test('refuses unknown accounts and candidates without two-factor authentication', function () {
        $candidate = User::factory()->candidate()->create();

        $this->artisan('app:extension-token', ['email' => 'nobody@example.com'])->assertFailed();
        $this->artisan('app:extension-token', ['email' => $candidate->email])->assertFailed();

        expect(PersonalAccessToken::query()->count())->toBe(0);
    });
});
