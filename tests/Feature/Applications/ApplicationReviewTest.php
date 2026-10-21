<?php

use App\Domain\Applications\ApplicationQuestionService;
use App\Domain\Applications\JobApplication;
use App\Domain\Applications\ReviewStatus;
use App\Domain\Audit\AuditEvent;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function reviewedApplicationOf(User $candidate, array $attributes = []): JobApplication
{
    return JobApplication::factory()->forProfile(profileOf($candidate))->create($attributes);
}

describe('assign', function () {
    test('the candidate assigns an application to a VA, and can unassign it', function () {
        $candidate = candidateWithTwoFactor();
        $va = vaOf($candidate);
        $application = reviewedApplicationOf($candidate);

        $this->actingAs($candidate)->patch(route('applications.review.assign', $application), ['assignee' => $va->ulid])->assertRedirect();
        expect($application->refresh()->assigned_user_id)->toBe($va->id);

        $this->actingAs($candidate)->patch(route('applications.review.assign', $application), ['assignee' => null]);
        expect($application->refresh()->assigned_user_id)->toBeNull();
    });

    test('a VA takes an unassigned application and hands back only their own', function () {
        $candidate = candidateWithTwoFactor();
        $va = vaOf($candidate);
        $other = vaOf($candidate);
        $application = reviewedApplicationOf($candidate);

        $this->actingAs($va)->patch(route('applications.review.assign', $application), ['assignee' => $other->ulid])->assertForbidden();
        $this->actingAs($va)->patch(route('applications.review.assign', $application), ['assignee' => $va->ulid])->assertRedirect();
        $this->actingAs($other)->patch(route('applications.review.assign', $application), ['assignee' => $other->ulid])->assertForbidden();
        $this->actingAs($other)->patch(route('applications.review.assign', $application), ['assignee' => null])->assertForbidden();
        $this->actingAs($va)->patch(route('applications.review.assign', $application), ['assignee' => null])->assertRedirect();

        expect($application->refresh()->assigned_user_id)->toBeNull();
    });

    test('nobody outside the team, and no deactivated VA, can be assigned', function () {
        $candidate = candidateWithTwoFactor();
        $application = reviewedApplicationOf($candidate);
        $outsider = vaOf(candidateWithTwoFactor());
        $inactive = vaOf($candidate);
        $inactive->forceFill(['is_active' => false])->save();

        foreach ([$outsider, $inactive] as $user) {
            $this->actingAs($candidate)
                ->patch(route('applications.review.assign', $application), ['assignee' => $user->ulid])
                ->assertSessionHasErrors('assignee');
        }
    });
});

describe('review', function () {
    test('a VA prepares and submits with a note; the candidate requests changes; the VA resubmits; the candidate approves', function () {
        $candidate = candidateWithTwoFactor();
        $va = vaOf($candidate);
        $application = reviewedApplicationOf($candidate, ['assigned_user_id' => $va->id]);

        $this->actingAs($va)->patch(route('applications.review.start', $application));
        expect($application->refresh()->review_status)->toBe(ReviewStatus::InProgress);

        $this->actingAs($va)->patch(route('applications.review.submit', $application), ['note' => 'Resume and answers ready.']);
        expect($application->refresh()->review_status)->toBe(ReviewStatus::InReview)
            ->and($application->review_note)->toBe('Resume and answers ready.');

        $this->actingAs($va)->patch(route('applications.review.request-changes', $application), ['note' => 'x'])->assertForbidden();
        $this->actingAs($candidate)->patch(route('applications.review.request-changes', $application), ['note' => ' '])->assertSessionHasErrors('note');
        $this->actingAs($candidate)->patch(route('applications.review.request-changes', $application), ['note' => 'Shorten the summary.']);
        expect($application->refresh()->review_status)->toBe(ReviewStatus::ChangesRequested)
            ->and($application->review_note)->toBe('Shorten the summary.');

        $this->actingAs($va)->patch(route('applications.review.submit', $application));
        $this->actingAs($va)->patch(route('applications.review.approve', $application))->assertForbidden();
        $this->actingAs($candidate)->patch(route('applications.review.approve', $application))->assertSessionHasNoErrors();

        expect($application->refresh()->review_status)->toBe(ReviewStatus::Approved)
            ->and($application->review_note)->toBeNull()
            ->and(inWorkspaceOf($candidate, fn () => AuditEvent::query()->where('action', 'like', 'application.review_%')->orderBy('id')->pluck('action')->all()))
            ->toBe(['application.review_start', 'application.review_submit', 'application.review_request_changes', 'application.review_submit', 'application.review_approve']);
    });

    test('steps that do not fit the current status are refused', function () {
        $candidate = candidateWithTwoFactor();
        $application = reviewedApplicationOf($candidate);

        $this->actingAs($candidate)
            ->patch(route('applications.review.request-changes', $application), ['note' => 'Nothing to review yet'])
            ->assertSessionHasErrors('review');
        $this->actingAs($candidate)->patch(route('applications.review.reopen', $application))->assertSessionHasErrors('review');
        $this->actingAs($candidate)->patch(route('applications.review.submit', $application))->assertForbidden();
    });

    test('the application is approved only once its resume and answers are approved', function () {
        $candidate = candidateWithTwoFactor();
        $application = reviewedApplicationOf($candidate);
        $questions = app(ApplicationQuestionService::class);
        $question = inWorkspaceOf($candidate, fn () => $questions->add($application, 'Why us?', null, null, $candidate));
        inWorkspaceOf($candidate, fn () => $questions->saveAnswer($question, 'Because.', null, 0, $candidate));

        $this->actingAs($candidate)
            ->patch(route('applications.review.approve', $application))
            ->assertSessionHasErrors(['review' => 'Approve these first: “Why us?”']);

        inWorkspaceOf($candidate, fn () => $questions->approveAnswer($question->refresh(), $candidate));
        $this->actingAs($candidate)->patch(route('applications.review.approve', $application))->assertSessionHasNoErrors();

        $this->actingAs($candidate)->patch(route('applications.review.reopen', $application));
        expect($application->refresh()->review_status)->toBe(ReviewStatus::InProgress);
    });
});

test('the detail page shows the review panel and a timeline of the application\'s events', function () {
    $candidate = candidateWithTwoFactor();
    $va = vaOf($candidate);
    $application = reviewedApplicationOf($candidate);
    $this->actingAs($candidate)->patch(route('applications.review.assign', $application), ['assignee' => $va->ulid]);
    $this->actingAs($va)->patch(route('applications.review.submit', $application), ['note' => 'Please check the salary answer.']);
    reviewedApplicationOf($candidate)->forceFill(['notes' => 'other'])->save();

    $this->actingAs($candidate)->get(route('applications.show', $application))
        ->assertInertia(fn (Assert $page) => $page
            ->where('application.assignee.name', $va->name)
            ->where('application.review_status', 'in_review')
            ->where('review.can.review', true)
            ->where('review.can.submit', false)
            ->where('review.team', fn ($team) => collect($team)->pluck('value')->contains($va->ulid))
            ->has('timeline', 2)
            ->where('timeline.0.sentence', 'sent an application for review')
            ->where('timeline.0.note', 'Please check the salary answer.')
            ->where('timeline.1.sentence', "assigned an application to {$va->name}"));

    $this->actingAs($va)->get(route('applications.show', $application))
        ->assertInertia(fn (Assert $page) => $page
            ->where('application.assignee.is_you', true)
            ->where('review.can.review', false)
            ->where('review.can.handBack', true)
            ->where('review.team', []));
});

test('quick filters narrow the list to my, unassigned, in-review and overdue applications', function () {
    $candidate = candidateWithTwoFactor();
    $va = vaOf($candidate);
    reviewedApplicationOf($candidate, ['company_name' => 'Mine', 'assigned_user_id' => $va->id]);
    reviewedApplicationOf($candidate, ['company_name' => 'Open']);
    reviewedApplicationOf($candidate, ['company_name' => 'Waiting', 'assigned_user_id' => $va->id, 'review_status' => ReviewStatus::InReview]);
    reviewedApplicationOf($candidate, ['company_name' => 'Late', 'deadline_on' => now()->subDay()]);

    $companies = fn (User $user, string $filter) => test()->actingAs($user)
        ->get(route('applications.index', ['filter' => $filter]))
        ->viewData('page')['props']['applications'];

    expect(collect($companies($va, 'mine'))->pluck('company_name')->sort()->values()->all())->toBe(['Mine', 'Waiting'])
        ->and(collect($companies($candidate, 'unassigned'))->pluck('company_name')->sort()->values()->all())->toBe(['Late', 'Open'])
        ->and(collect($companies($candidate, 'in_review'))->pluck('company_name')->all())->toBe(['Waiting'])
        ->and(collect($companies($candidate, 'overdue'))->pluck('company_name')->all())->toBe(['Late']);
});

test('the dashboard tells the candidate what waits for review and a VA what is theirs', function () {
    $candidate = candidateWithTwoFactor();
    $va = vaOf($candidate);
    reviewedApplicationOf($candidate, ['assigned_user_id' => $va->id, 'review_status' => ReviewStatus::InReview]);
    reviewedApplicationOf($candidate, ['assigned_user_id' => $va->id, 'review_status' => ReviewStatus::ChangesRequested]);
    reviewedApplicationOf($candidate, ['assigned_user_id' => $va->id]);

    $this->actingAs($candidate)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('attention.0.key', 'reviews')->where('attention.0.count', 1));

    $this->actingAs($va)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('attention.0.key', 'changes')
            ->where('attention.0.count', 1)
            ->where('attention.1.key', 'assigned')
            ->where('attention.1.count', 1));
});
