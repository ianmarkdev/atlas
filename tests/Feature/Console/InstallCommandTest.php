<?php

use App\Domain\Audit\AuditEvent;
use App\Domain\Knowledge\CandidateProfile;
use App\Domain\Tenancy\Role;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\Workspace;
use App\Models\User;

/**
 * @return array<string, string>
 */
function installOptions(array $overrides = []): array
{
    return [
        '--workspace' => 'Alex applications',
        '--name' => 'Alex Morgan',
        '--email' => 'Alex@Example.com',
        '--password' => 'correct-horse-battery',
        ...$overrides,
    ];
}

test('installing creates the workspace, the candidate account and the candidate profile', function () {
    $this->artisan('app:install', installOptions())->assertSuccessful();

    $workspace = Workspace::query()->sole();
    $candidate = User::query()->sole();
    expect($workspace->name)->toBe('Alex applications')
        ->and($workspace->created_by)->toBe($candidate->id)
        ->and($candidate->email)->toBe('alex@example.com')
        ->and($candidate->email_verified_at)->not->toBeNull()
        ->and($candidate->role())->toBe(Role::Candidate);

    [$profile, $event] = app(TenantContext::class)->run($workspace, fn () => [
        CandidateProfile::query()->sole(),
        AuditEvent::query()->sole(),
    ]);
    expect($profile->user_id)->toBe($candidate->id)
        ->and($profile->display_name)->toBe('Alex Morgan')
        ->and($event->action)->toBe('workspace.installed');
});

test('installing a second time is refused and changes nothing', function () {
    $this->artisan('app:install', installOptions())->assertSuccessful();

    $this->artisan('app:install', installOptions(['--email' => 'second@example.com']))->assertFailed();

    expect(Workspace::query()->count())->toBe(1)
        ->and(User::query()->where('email', 'second@example.com')->exists())->toBeFalse();
});

test('installing with an invalid email is refused', function () {
    $this->artisan('app:install', installOptions(['--email' => 'not-an-email']))
        ->expectsOutputToContain('The email field must be a valid email address.')
        ->assertFailed();

    expect(Workspace::query()->exists())->toBeFalse();
});
