<?php

use App\Domain\Ai\Clients\FakeLlmClient;
use App\Domain\Ai\LlmRouter;
use App\Domain\Applications\JobAnalysis;
use App\Domain\Applications\JobApplication;
use App\Domain\Knowledge\Claim;
use App\Domain\Knowledge\Employment;
use App\Domain\Resumes\ResumeVariant;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A resume showing these bullet points under one job.
 *
 * @param  list<Claim>  $claims
 */
function rankedResumeOf(User $candidate, Employment $employment, string $name, array $claims, bool $isDefault = false): ResumeVariant
{
    $factory = ResumeVariant::factory()->forProfile(profileOf($candidate))->withDocument(['experience' => [[
        'employment' => $employment->ulid, 'display_title' => null, 'description' => null, 'projects' => [],
        'bullets' => array_map(fn (Claim $claim): array => ['claim' => $claim->ulid, 'text' => null], $claims),
    ]]]);

    return ($isDefault ? $factory->default() : $factory)->create(['name' => $name]);
}

/**
 * Analyse an application whose job asks for PostgreSQL, Kubernetes and Terraform, and German as a nice-to-have.
 */
function rankedApplicationOf(User $candidate): JobApplication
{
    $application = JobApplication::factory()->forProfile(profileOf($candidate))
        ->withJobDescription('Senior engineer: PostgreSQL, Kubernetes, Terraform. German is a plus.')
        ->create(['company_name' => 'Fabrikam', 'role_title' => 'Senior Engineer']);
    app(LlmRouter::class)->fake(new FakeLlmClient([[
        'summary' => 'Senior backend role.', 'seniority' => 'senior',
        'must_haves' => [
            ['requirement' => 'PostgreSQL query tuning', 'quote' => 'PostgreSQL'],
            ['requirement' => 'Kubernetes operations', 'quote' => 'Kubernetes'],
            ['requirement' => 'Terraform infrastructure as code', 'quote' => 'Terraform'],
        ],
        'nice_to_haves' => [['requirement' => 'German language', 'quote' => 'German is a plus.']],
        'employer_language' => [], 'questions' => [], 'red_flags' => [], 'injection_warnings' => [],
    ]]));
    test()->actingAs($candidate)->post(route('applications.analysis.store', $application));

    return $application;
}

/**
 * A job with a fixed title and employer: a bullet point is indexed with them, so random words
 * from the factory could otherwise match a requirement.
 */
function rankedJobOf(User $candidate): Employment
{
    return Employment::factory()->forProfile(profileOf($candidate))->verified($candidate)->create(['title' => 'Backend Developer', 'employer_name' => 'Northwind']);
}

function rankingOf(User $candidate): array
{
    return inWorkspaceOf($candidate, fn () => JobAnalysis::query()->latest('id')->firstOrFail()->resume_ranking);
}

test('resumes are ranked by the requirements their own bullet points cover, and each gap says where it can be closed', function () {
    $candidate = candidateWithTwoFactor();
    $employment = rankedJobOf($candidate);
    $postgres = claimOf($candidate, 'Tuned PostgreSQL indexes and queries, halving p95 latency', $employment);
    $german = claimOf($candidate, 'Speaks fluent German', $employment);
    claimOf($candidate, 'Ran Kubernetes operations for 40 services', $employment);
    $unconfirmed = Claim::factory()->forProfile(profileOf($candidate))->about($employment)->stating('Sped up PostgreSQL query tuning for reports')->create();
    rankedResumeOf($candidate, $employment, 'Languages', [$german, $unconfirmed], isDefault: true);
    rankedResumeOf($candidate, $employment, 'Backend', [$postgres]);

    rankedApplicationOf($candidate);

    [$backend, $languages] = rankingOf($candidate)['resumes'];
    $rows = collect($backend['requirements'])->keyBy('requirement');
    expect(array_column(rankingOf($candidate)['resumes'], 'name'))->toBe(['Backend', 'Languages'])
        ->and($backend['must'])->toEqual(['strong' => 1, 'partial' => 0, 'gap' => 2])
        ->and($languages['must'])->toEqual(['strong' => 0, 'partial' => 1, 'gap' => 2])
        ->and($languages['nice'])->toEqual(['strong' => 1, 'partial' => 0, 'gap' => 0])
        ->and($rows['PostgreSQL query tuning'])->toMatchArray(['coverage' => 'strong', 'missing_from' => null])
        ->and($rows['PostgreSQL query tuning']['evidence'][0]['text'])->toContain('Tuned PostgreSQL indexes')
        // On a bullet point in the library, not on this resume: add it while tailoring.
        ->and($rows['Kubernetes operations'])->toMatchArray(['coverage' => 'gap', 'library_coverage' => 'strong', 'missing_from' => 'library'])
        // Nowhere in the library: a real gap, not something to add.
        ->and($rows['Terraform infrastructure as code'])->toMatchArray(['coverage' => 'gap', 'library_coverage' => 'gap', 'missing_from' => 'experience']);
});

test('resumes that cover the same requirements rank the default first, then by name', function () {
    $candidate = candidateWithTwoFactor();
    $employment = rankedJobOf($candidate);
    $postgres = claimOf($candidate, 'Tuned PostgreSQL indexes and queries, halving p95 latency', $employment);
    rankedResumeOf($candidate, $employment, 'Alpha', [$postgres]);
    rankedResumeOf($candidate, $employment, 'Zulu', [$postgres], isDefault: true);
    rankedResumeOf($candidate, $employment, 'Bravo', [$postgres]);

    rankedApplicationOf($candidate);

    expect(array_column(rankingOf($candidate)['resumes'], 'name'))->toBe(['Zulu', 'Alpha', 'Bravo']);
});

test('the application page offers the ranking until a resume is started, and says when resumes changed since', function () {
    $candidate = candidateWithTwoFactor();
    $employment = rankedJobOf($candidate);
    $postgres = claimOf($candidate, 'Tuned PostgreSQL indexes and queries, halving p95 latency', $employment);
    $backend = rankedResumeOf($candidate, $employment, 'Backend', [$postgres]);
    $application = rankedApplicationOf($candidate);

    $this->actingAs($candidate)->get(route('applications.show', $application))
        ->assertInertia(fn (Assert $page) => $page
            ->where('resumeRanking.resumes.0.ulid', $backend->ulid)
            ->missing('resumeRanking.resumes.0.revision_id')
            ->where('resumeRanking.stale', false));

    rankedResumeOf($candidate, $employment, 'Newer', [$postgres]);

    $this->actingAs($candidate)->get(route('applications.show', $application))
        ->assertInertia(fn (Assert $page) => $page->where('resumeRanking.stale', true));

    $analysis = inWorkspaceOf($candidate, fn () => JobAnalysis::query()->sole());
    $this->actingAs($candidate)->post(route('applications.analysis.refresh', [$application, $analysis]));

    $this->actingAs($candidate)->get(route('applications.show', $application))
        ->assertInertia(fn (Assert $page) => $page->where('resumeRanking.stale', false)->has('resumeRanking.resumes', 2));

    $this->actingAs($candidate)->post(route('applications.resume.store', $application), ['variant' => $backend->ulid]);

    $this->actingAs($candidate)->get(route('applications.show', $application))
        ->assertInertia(fn (Assert $page) => $page->where('resumeRanking', null));
});
