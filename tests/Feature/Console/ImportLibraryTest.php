<?php

use App\Domain\Knowledge\Claim;
use App\Domain\Knowledge\ClaimKind;
use App\Domain\Knowledge\ClaimSource;
use App\Domain\Knowledge\ClaimStatus;
use App\Domain\Knowledge\Employment;
use App\Domain\Knowledge\EmploymentType;
use App\Domain\Knowledge\KnowledgeStatus;
use App\Domain\Knowledge\Project;

function libraryFixture(string $name): string
{
    return base_path("tests/Fixtures/library/{$name}.md");
}

/**
 * @return array{employments: int, projects: int, claims: int}
 */
function libraryCounts(): array
{
    return [
        'employments' => Employment::query()->count(),
        'projects' => Project::query()->count(),
        'claims' => Claim::query()->count(),
    ];
}

it('imports jobs and projects as proposed records, keeping extra fields in the summary', function () {
    $candidate = candidateWithTwoFactor();

    $this->artisan('app:import-library', ['files' => [libraryFixture('profile')]])
        ->expectsOutputToContain('Job "Developer at Initech" has no start date')
        ->assertSuccessful();

    [$employment, $settlement, $recipes, $initech] = inWorkspaceOf($candidate, fn () => [
        Employment::query()->sole(),
        Project::query()->where('name', 'Settlement Engine')->sole(),
        Project::query()->where('name', 'Recipe App — Offline First')->sole(),
        Employment::query()->where('employer_name', 'Initech')->exists(),
    ]);
    expect($employment->status)->toBe(KnowledgeStatus::Proposed)
        ->and($employment->employer_name)->toBe('Acme Payments Ltd')
        ->and($employment->employment_type)->toBe(EmploymentType::Contract)
        ->and($employment->start_date->toDateString())->toBe('2022-03-01')
        ->and($employment->end_date)->toBeNull()
        ->and($employment->summary)->toBe("Built the settlement service.\nAbout Employer: Card processor.\nScale and impact: 2 million payments a day.")
        ->and($initech)->toBeFalse()
        ->and($settlement->status)->toBe(KnowledgeStatus::Proposed)
        ->and($settlement->employment_id)->toBe($employment->id)
        ->and($settlement->client_name)->toBeNull()
        ->and($settlement->end_date?->toDateString())->toBe('2023-01-01')
        ->and($settlement->url)->toBe('https://acme.test/settle')
        ->and($settlement->summary)->toBe("Replaced nightly batch settlement.\nURL: https://acme.test/settle; https://status.acme.test/")
        ->and($recipes->employment_id)->toBeNull()
        ->and($recipes->start_date?->toDateString())->toBe('2024-01-01')
        ->and($recipes->url)->toBeNull();
});

it('imports claims about their job or project as proposed, even when the candidate runs it', function () {
    $candidate = candidateWithTwoFactor();

    $this->artisan('app:import-library', ['files' => [libraryFixture('profile')]])
        ->expectsOutputToContain('No job or project "Globex" for: Ran the on-call rota.')
        ->assertSuccessful();

    [$achievement, $skill, $sources, $count] = inWorkspaceOf($candidate, fn () => [
        Claim::query()->with(['currentRevision', 'subject'])->where('kind', ClaimKind::Achievement)->sole(),
        Claim::query()->with('subject')->where('kind', ClaimKind::Skill)->sole(),
        ClaimSource::query()->count(),
        Claim::query()->count(),
    ]);
    expect($count)->toBe(2)
        ->and($achievement->statement())->toBe('Cut settlement time from 6 hours to 20 minutes.')
        ->and($achievement->status)->toBe(ClaimStatus::Proposed)
        ->and($achievement->origin)->toBe('imported')
        ->and($achievement->subject)->toBeInstanceOf(Employment::class)
        ->and($skill->subject?->name)->toBe('Recipe App — Offline First')
        ->and($sources)->toBe(0);
});

it('attaches CV bullet points to the job or project they are listed under, skipping duplicates and tech lists', function () {
    $candidate = candidateWithTwoFactor();

    $this->artisan('app:import-library', ['files' => [libraryFixture('profile'), libraryFixture('cv')]])
        ->expectsOutputToContain('duplicate, skipped: Acme Payments: Cut settlement time')
        ->assertSuccessful();

    [$queued, $shipped, $count] = inWorkspaceOf($candidate, fn () => [
        Claim::query()->with('subject')->whereHas('currentRevision', fn ($query) => $query->where('statement', 'like', 'Moved settlement%'))->sole(),
        Claim::query()->with('subject')->whereHas('currentRevision', fn ($query) => $query->where('statement', 'like', 'Shipped the app%'))->sole(),
        Claim::query()->count(),
    ]);
    expect($count)->toBe(4)
        ->and($queued->subject)->toBeInstanceOf(Employment::class)
        ->and($queued->kind)->toBe(ClaimKind::Responsibility)
        ->and($shipped->subject?->name)->toBe('Recipe App — Offline First')
        ->and($shipped->kind)->toBe(ClaimKind::Achievement);
});

it('adds nothing when the same documents are imported again', function () {
    $candidate = candidateWithTwoFactor();
    $files = ['files' => [libraryFixture('profile'), libraryFixture('cv')]];
    $this->artisan('app:import-library', $files)->assertSuccessful();
    $before = inWorkspaceOf($candidate, libraryCounts(...));

    $this->artisan('app:import-library', $files)->assertSuccessful();

    expect(inWorkspaceOf($candidate, libraryCounts(...)))->toBe($before);
});

it('saves nothing on a dry run', function () {
    $candidate = candidateWithTwoFactor();

    $this->artisan('app:import-library', ['files' => [libraryFixture('profile')], '--dry-run' => true])
        ->expectsOutputToContain('Dry run, nothing saved')
        ->assertSuccessful();

    expect(inWorkspaceOf($candidate, libraryCounts(...)))->toBe(['employments' => 0, 'projects' => 0, 'claims' => 0]);
});

it('rejects a file that does not exist', function () {
    candidateWithTwoFactor();

    $this->artisan('app:import-library', ['files' => ['missing.md']])
        ->expectsOutputToContain('No such file: missing.md')
        ->assertFailed();
});
