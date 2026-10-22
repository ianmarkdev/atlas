<?php

use App\Domain\Applications\ApplicationResume;
use App\Domain\Applications\JobApplication;
use App\Domain\Knowledge\Employment;
use App\Domain\Resumes\ResumeDiff;
use App\Domain\Resumes\ResumeVariant;
use App\Http\Controllers\Profile\ResumeController;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array<string, mixed>
 */
function diffJob(Employment $employment, array $bullets, ?string $displayTitle = null): array
{
    return ['employment' => $employment->ulid, 'display_title' => $displayTitle, 'description' => null, 'bullets' => $bullets, 'projects' => []];
}

function diffLibrary(User $candidate): array
{
    return inWorkspaceOf($candidate, fn () => ResumeController::editorLibrary());
}

test('the diff lists header, job, bullet and order changes with readable labels', function () {
    $candidate = candidateWithTwoFactor();
    $first = Employment::factory()->forProfile(profileOf($candidate))->verified($candidate)->create(['title' => 'Developer', 'employer_name' => 'Acme']);
    $second = Employment::factory()->forProfile(profileOf($candidate))->verified($candidate)->create(['title' => 'Engineer', 'employer_name' => 'Globex']);
    $kept = claimOf($candidate, 'Built the billing API', $first);
    $dropped = claimOf($candidate, 'Ran the on-call rota', $first);
    $added = claimOf($candidate, 'Mentored two juniors', $first);

    $before = ['title' => 'Backend Engineer', 'summary' => 'PHP developer.', 'experience' => [
        diffJob($first, [['claim' => $kept->ulid, 'text' => null], ['claim' => $dropped->ulid, 'text' => null]]),
        diffJob($second, []),
    ], 'projects' => [], 'skills' => [], 'education' => [], 'certifications' => [], 'languages' => [], 'links' => []];
    $after = ['title' => 'Senior Payments Engineer', 'summary' => 'PHP developer.', 'experience' => [
        diffJob($second, []),
        diffJob($first, [['claim' => $kept->ulid, 'text' => 'Built the payments API used by 40,000 merchants'], ['claim' => $added->ulid, 'text' => null]], 'Senior Developer'),
    ], 'projects' => [], 'skills' => [], 'education' => [], 'certifications' => [], 'languages' => [], 'links' => []];

    $changes = collect(app(ResumeDiff::class)->between($before, $after, diffLibrary($candidate)));

    expect($changes->firstWhere('item', 'Title'))->toMatchArray(['change' => 'changed', 'before' => 'Backend Engineer', 'after' => 'Senior Payments Engineer'])
        ->and($changes->firstWhere('change', 'reordered')['section'])->toBe('Experience')
        ->and($changes->firstWhere('item', 'Developer · Acme: shown as'))->toMatchArray(['before' => null, 'after' => 'Senior Developer'])
        ->and($changes->where('change', 'added')->pluck('after')->all())->toBe(['Mentored two juniors'])
        ->and($changes->where('change', 'removed')->pluck('before')->all())->toBe(['Ran the on-call rota'])
        ->and($changes->firstWhere('change', 'reworded'))->toMatchArray(['before' => 'Built the billing API', 'after' => 'Built the payments API used by 40,000 merchants'])
        ->and($changes->where('item', 'Summary'))->toBeEmpty();
});

test('the tailored resume page shows the changes against the revision it was copied from', function () {
    $candidate = candidateWithTwoFactor();
    $variant = ResumeVariant::factory()->forProfile(profileOf($candidate))->withDocument(['title' => 'PHP Engineer'])->create(['name' => 'PHP focused']);
    $application = JobApplication::factory()->forProfile(profileOf($candidate))->create();
    $this->actingAs($candidate)->post(route('applications.resume.store', $application), ['variant' => $variant->ulid]);

    $this->actingAs($candidate)->get(route('applications.resume.show', $application))
        ->assertInertia(fn (Assert $page) => $page->where('changes', [])->where('baseRevisionNo', 1));

    $resume = inWorkspaceOf($candidate, fn () => ApplicationResume::query()->sole());
    $this->actingAs($candidate)->put(route('applications.resume.update', $application), [
        'document' => ['schema_version' => 1, 'title' => 'Payments Engineer', 'summary' => null, 'experience' => [], 'projects' => []],
        'lock_version' => $resume->lock_version,
    ]);

    $this->actingAs($candidate)->get(route('applications.resume.show', $application))
        ->assertInertia(fn (Assert $page) => $page
            ->has('changes', 1)
            ->where('changes.0.item', 'Title')
            ->where('changes.0.after', 'Payments Engineer'));
});
