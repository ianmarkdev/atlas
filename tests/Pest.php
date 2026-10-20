<?php

use App\Domain\Knowledge\CandidateProfile;
use App\Domain\Knowledge\Claim;
use App\Domain\Knowledge\Employment;
use App\Domain\Knowledge\Project;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\Workspace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * The workspace's candidate, with two-factor authentication set up so they pass the 2FA requirement.
 */
function candidateWithTwoFactor(): User
{
    return User::factory()->withTwoFactor()->candidate()->create();
}

function workspaceOf(User $user): Workspace
{
    return $user->membership->workspace;
}

function vaOf(User $candidate): User
{
    return User::factory()->va(workspaceOf($candidate))->create();
}

function profileOf(User $candidate): CandidateProfile
{
    return CandidateProfile::withoutGlobalScopes()->where('user_id', $candidate->id)->sole();
}

function employmentOf(User $candidate): Employment
{
    return Employment::factory()->forProfile(profileOf($candidate))->verified($candidate)->create();
}

/**
 * A verified claim of the candidate, about the given job or project (or general).
 */
function claimOf(User $candidate, string $statement, Employment|Project|null $subject = null): Claim
{
    $factory = Claim::factory()->forProfile(profileOf($candidate))->stating($statement);

    return ($subject === null ? $factory : $factory->about($subject))->verified($candidate)->create();
}

/**
 * Run a callback with the given user's workspace as tenant context (for asserting on tenant models).
 *
 * @template TReturn
 *
 * @param  callable(): TReturn  $callback
 * @return TReturn
 */
function inWorkspaceOf(User $user, callable $callback): mixed
{
    return app(TenantContext::class)->run(workspaceOf($user), $callback);
}
