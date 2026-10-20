<?php

use App\Domain\Audit\AuditEvent;
use App\Domain\Knowledge\Employment;
use App\Domain\Knowledge\KnowledgeProposal;
use App\Domain\Knowledge\KnowledgeStatus;
use App\Domain\Knowledge\Project;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array<string, mixed>
 */
function employmentInput(array $overrides = []): array
{
    return [
        'employer_name' => 'Acme Ltd.',
        'title' => 'Senior Developer',
        'employment_type' => 'full_time',
        'location' => 'London',
        'start_date' => '2021-03',
        'end_date' => '2023-08',
        'summary' => "Backend lead for the billing platform.\n\nLaravel, Postgres.",
        ...$overrides,
    ];
}

describe('create', function () {
    test('an employment the candidate adds is verified and audited', function () {
        $candidate = candidateWithTwoFactor();

        $this->actingAs($candidate)
            ->post(route('knowledge.employments.store'), employmentInput())
            ->assertRedirect(route('knowledge.index'));

        [$employment, $event] = inWorkspaceOf($candidate, fn () => [Employment::query()->sole(), AuditEvent::query()->sole()]);
        expect($employment->status)->toBe(KnowledgeStatus::Verified)
            ->and($employment->verified_by)->toBe($candidate->id)
            ->and($employment->candidate_profile_id)->toBe(profileOf($candidate)->id)
            ->and($employment->employer_normalized)->toBe('acme')
            ->and($employment->start_date->toDateString())->toBe('2021-03-01')
            ->and($employment->end_date?->toDateString())->toBe('2023-08-01')
            ->and($event->action)->toBe('employment.created');
    });

    test('an employment a VA adds stays proposed until the candidate verifies it', function () {
        $candidate = candidateWithTwoFactor();

        $this->actingAs(vaOf($candidate))->post(route('knowledge.employments.store'), employmentInput());

        $employment = inWorkspaceOf($candidate, fn () => Employment::query()->sole());
        expect($employment->status)->toBe(KnowledgeStatus::Proposed)
            ->and($employment->verified_by)->toBeNull();
    });

    test('a current job has no end month', function () {
        $candidate = candidateWithTwoFactor();

        $this->actingAs($candidate)
            ->post(route('knowledge.employments.store'), employmentInput(['end_date' => null]))
            ->assertSessionHasNoErrors();

        expect(inWorkspaceOf($candidate, fn () => Employment::query()->sole()->end_date))->toBeNull();
    });

    test('an end month before the start month is rejected', function () {
        $this->actingAs(candidateWithTwoFactor())
            ->post(route('knowledge.employments.store'), employmentInput(['start_date' => '2023-05', 'end_date' => '2022-01']))
            ->assertSessionHasErrors(['end_date' => 'The end date field must be a date after or equal to start date.']);
    });

    test('a summary over 5,000 characters is rejected', function () {
        $this->actingAs(candidateWithTwoFactor())
            ->post(route('knowledge.employments.store'), employmentInput(['summary' => str_repeat('a', 5001)]))
            ->assertSessionHasErrors(['summary' => 'The summary field must not be greater than 5000 characters.']);
    });
});

describe('update', function () {
    test('the candidate edits a verified employment', function () {
        $candidate = candidateWithTwoFactor();
        $employment = Employment::factory()->forProfile(profileOf($candidate))->verified($candidate)->create();

        $this->actingAs($candidate)
            ->put(route('knowledge.employments.update', $employment), employmentInput(['title' => 'Lead Developer', 'lock_version' => 0]))
            ->assertRedirect(route('knowledge.index'));

        $employment->refresh();
        expect($employment->title)->toBe('Lead Developer')
            ->and($employment->lock_version)->toBe(1);
    });

    test('an edit based on an outdated version is rejected and changes nothing', function () {
        $candidate = candidateWithTwoFactor();
        $employment = Employment::factory()->forProfile(profileOf($candidate))->verified($candidate)->create(['lock_version' => 3, 'title' => 'Original']);

        $this->actingAs($candidate)
            ->put(route('knowledge.employments.update', $employment), employmentInput(['title' => 'Overwrite', 'lock_version' => 2]))
            ->assertSessionHasErrors('lock_version');

        expect($employment->refresh()->title)->toBe('Original');
    });

    test('a VA edits a proposed employment', function () {
        $candidate = candidateWithTwoFactor();
        $employment = Employment::factory()->forProfile(profileOf($candidate))->create();

        $this->actingAs(vaOf($candidate))
            ->put(route('knowledge.employments.update', $employment), employmentInput(['title' => 'Corrected title', 'lock_version' => 0]))
            ->assertRedirect(route('knowledge.index'));

        expect($employment->refresh())
            ->title->toBe('Corrected title')
            ->status->toBe(KnowledgeStatus::Proposed);
    });

    test('a VA\'s edit of a verified employment becomes a suggestion and leaves it unchanged', function () {
        $candidate = candidateWithTwoFactor();
        $employment = Employment::factory()->forProfile(profileOf($candidate))->verified($candidate)->create(['title' => 'Original']);

        $this->actingAs(vaOf($candidate))
            ->put(route('knowledge.employments.update', $employment), employmentInput(['title' => 'Changed', 'lock_version' => 0]))
            ->assertRedirect(route('knowledge.index'));

        expect($employment->refresh()->title)->toBe('Original')
            ->and(inWorkspaceOf($candidate, fn () => KnowledgeProposal::query()->sole()->payload['title']))->toBe('Changed');
    });

    test('an employment of another workspace responds 404', function () {
        $candidate = candidateWithTwoFactor();
        $other = Employment::factory()->forProfile(profileOf(candidateWithTwoFactor()))->create();

        $this->actingAs($candidate)->get(route('knowledge.employments.edit', $other))->assertNotFound();
        $this->actingAs($candidate)->patch(route('knowledge.employments.verify', $other))->assertNotFound();
    });
});

describe('status changes', function () {
    test('the candidate verifies a proposed employment', function () {
        $candidate = candidateWithTwoFactor();
        $employment = Employment::factory()->forProfile(profileOf($candidate))->create();

        $this->actingAs($candidate)->patch(route('knowledge.employments.verify', $employment))->assertRedirect();

        expect($employment->refresh())
            ->status->toBe(KnowledgeStatus::Verified)
            ->verified_by->toBe($candidate->id);
    });

    test('a VA cannot verify an employment', function () {
        $candidate = candidateWithTwoFactor();
        $employment = Employment::factory()->forProfile(profileOf($candidate))->create();

        $this->actingAs(vaOf($candidate))->patch(route('knowledge.employments.verify', $employment))->assertForbidden();

        expect($employment->refresh()->status)->toBe(KnowledgeStatus::Proposed);
    });

    test('the candidate archives an employment and restores it as verified', function () {
        $candidate = candidateWithTwoFactor();
        $employment = Employment::factory()->forProfile(profileOf($candidate))->verified($candidate)->create();

        $this->actingAs($candidate)->patch(route('knowledge.employments.archive', $employment));
        expect($employment->refresh()->status)->toBe(KnowledgeStatus::Archived);

        $this->actingAs($candidate)->patch(route('knowledge.employments.restore', $employment));
        expect($employment->refresh()->status)->toBe(KnowledgeStatus::Verified);
    });

    test('a VA cannot archive an employment', function () {
        $candidate = candidateWithTwoFactor();
        $employment = Employment::factory()->forProfile(profileOf($candidate))->create();

        $this->actingAs(vaOf($candidate))->patch(route('knowledge.employments.archive', $employment))->assertForbidden();
    });
});

describe('overview', function () {
    test('the overview lists this workspace\'s employments and hides archived ones by default', function () {
        $candidate = candidateWithTwoFactor();
        $profile = profileOf($candidate);
        $current = Employment::factory()->forProfile($profile)->verified($candidate)->create(['title' => 'Current role']);
        Employment::factory()->forProfile($profile)->archived()->create(['title' => 'Archived role']);
        Employment::factory()->forProfile(profileOf(candidateWithTwoFactor()))->create(['title' => 'Other workspace']);

        $this->actingAs($candidate)->get(route('knowledge.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('knowledge/index')
                ->has('employments', 1)
                ->where('employments.0.ulid', $current->ulid)
                ->where('employments.0.can.update', true)
                ->where('employments.0.can.verify', false));
    });

    test('the overview nests each employment\'s projects as a list', function () {
        $candidate = candidateWithTwoFactor();
        $employment = Employment::factory()->forProfile(profileOf($candidate))->verified($candidate)->create();
        $project = Project::factory()->forProfile(profileOf($candidate))->create(['employment_id' => $employment->id]);

        $this->actingAs($candidate)->get(route('knowledge.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('employments.0.projects', 1)
                ->where('employments.0.projects.0.ulid', $project->ulid)
                ->where('employments.0.projects.0.employment', $employment->ulid));
    });

    test('the overview includes archived employments when asked', function () {
        $candidate = candidateWithTwoFactor();
        Employment::factory()->forProfile(profileOf($candidate))->archived()->create();

        $this->actingAs($candidate)->get(route('knowledge.index', ['archived' => 1]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('employments', 1)
                ->where('employments.0.status', 'archived')
                ->where('employments.0.can.restore', true));
    });
});
