<?php

use App\Domain\Knowledge\ClaimRevision;
use App\Domain\Knowledge\ClaimSource;
use App\Domain\Knowledge\Employment;
use App\Domain\Knowledge\KnowledgeProposal;
use App\Domain\Knowledge\ProposalStatus;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function suggestClaimChange(User $va, mixed $claim, string $statement, array $extra = []): void
{
    test()->actingAs($va)->put(route('knowledge.claims.update', $claim), [
        'kind' => 'achievement',
        'statement' => $statement,
        'lock_version' => $claim->lock_version,
        ...$extra,
    ]);
}

function onlyProposal(User $candidate): KnowledgeProposal
{
    return inWorkspaceOf($candidate, fn () => KnowledgeProposal::query()->latest('id')->firstOrFail());
}

test('approving a suggested claim change appends a revision confirmed by the candidate', function () {
    $candidate = candidateWithTwoFactor();
    $claim = claimOf($candidate, 'Led a team of four', employmentOf($candidate));
    suggestClaimChange(vaOf($candidate), $claim, 'Led a team of five', ['rationale' => 'The CV says five.']);
    $proposal = onlyProposal($candidate);

    $this->actingAs($candidate)
        ->patch(route('knowledge.proposals.approve', $proposal))
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', 'Change approved and applied.');

    $claim->refresh()->load('currentRevision');
    expect($claim->statement())->toBe('Led a team of five')
        ->and($claim->currentRevision?->created_via)->toBe("proposal:{$proposal->id}")
        ->and($proposal->refresh()->status)->toBe(ProposalStatus::Approved)
        ->and($proposal->decided_by)->toBe($candidate->id)
        ->and(inWorkspaceOf($candidate, fn () => ClaimSource::query()->where('claim_revision_id', $claim->current_revision_id)->value('confirmed_by')))
        ->toBe($candidate->id);
});

test('approving a suggested experience change applies it', function () {
    $candidate = candidateWithTwoFactor();
    $employment = Employment::factory()->forProfile(profileOf($candidate))->verified($candidate)->create(['title' => 'Developer', 'lock_version' => 0]);

    $this->actingAs(vaOf($candidate))->put(route('knowledge.employments.update', $employment), [
        'employer_name' => $employment->employer_name,
        'title' => 'Senior Developer',
        'start_date' => $employment->start_date->format('Y-m'),
        'lock_version' => 0,
    ]);

    $this->actingAs($candidate)->patch(route('knowledge.proposals.approve', onlyProposal($candidate)));

    expect($employment->refresh()->title)->toBe('Senior Developer');
});

test('a suggestion for an item that changed afterwards is marked as a conflict and not applied', function () {
    $candidate = candidateWithTwoFactor();
    $claim = claimOf($candidate, 'Led a team of four');
    suggestClaimChange(vaOf($candidate), $claim, 'Led a team of five');
    $this->actingAs($candidate)->put(route('knowledge.claims.update', $claim), [
        'kind' => 'achievement',
        'statement' => 'Led a team of four engineers',
        'lock_version' => 0,
    ]);

    $this->actingAs($candidate)
        ->patch(route('knowledge.proposals.approve', onlyProposal($candidate)))
        ->assertInertiaFlash('toast.type', 'error');

    expect(onlyProposal($candidate)->status)->toBe(ProposalStatus::Conflict)
        ->and($claim->refresh()->load('currentRevision')->statement())->toBe('Led a team of four engineers');
});

test('rejecting keeps the item unchanged and records the note', function () {
    $candidate = candidateWithTwoFactor();
    $claim = claimOf($candidate, 'Led a team of four');
    suggestClaimChange(vaOf($candidate), $claim, 'Led a team of forty');

    $this->actingAs($candidate)->patch(route('knowledge.proposals.reject', onlyProposal($candidate)), ['decision_note' => 'It was four.']);

    expect(onlyProposal($candidate))
        ->status->toBe(ProposalStatus::Rejected)
        ->decision_note->toBe('It was four.')
        ->and(inWorkspaceOf($candidate, fn () => ClaimRevision::query()->where('claim_id', $claim->id)->count()))->toBe(1);
});

test('a newer suggestion for the same item replaces the pending one', function () {
    $candidate = candidateWithTwoFactor();
    $va = vaOf($candidate);
    $claim = claimOf($candidate, 'Led a team of four');
    suggestClaimChange($va, $claim, 'First suggestion');
    suggestClaimChange($va, $claim, 'Second suggestion');

    $statuses = inWorkspaceOf($candidate, fn () => KnowledgeProposal::query()->orderBy('id')->pluck('status')->map->value->all());
    expect($statuses)->toBe(['superseded', 'pending']);
});

test('a suggestion based on an outdated form is rejected', function () {
    $candidate = candidateWithTwoFactor();
    $claim = claimOf($candidate, 'Led a team of four');
    $claim->forceFill(['lock_version' => 2])->save();

    $this->actingAs(vaOf($candidate))
        ->put(route('knowledge.claims.update', $claim), ['kind' => 'achievement', 'statement' => 'Old view', 'lock_version' => 1])
        ->assertSessionHasErrors('lock_version');

    expect(inWorkspaceOf($candidate, fn () => KnowledgeProposal::query()->count()))->toBe(0);
});

test('a decided suggestion cannot be decided again', function () {
    $candidate = candidateWithTwoFactor();
    suggestClaimChange(vaOf($candidate), claimOf($candidate, 'Led a team of four'), 'Led a team of five');
    $proposal = onlyProposal($candidate);
    $this->actingAs($candidate)->patch(route('knowledge.proposals.reject', $proposal));

    $this->actingAs($candidate)
        ->patch(route('knowledge.proposals.approve', $proposal))
        ->assertSessionHasErrors(['proposal' => 'This suggestion has already been decided.']);
});

test('VAs cannot approve or reject suggestions', function () {
    $candidate = candidateWithTwoFactor();
    $va = vaOf($candidate);
    suggestClaimChange($va, claimOf($candidate, 'Led a team of four'), 'Led a team of five');
    $proposal = onlyProposal($candidate);

    $this->actingAs($va)->patch(route('knowledge.proposals.approve', $proposal))->assertForbidden();
    $this->actingAs($va)->patch(route('knowledge.proposals.reject', $proposal))->assertForbidden();
});

test('the candidate reviews pending suggestions with what changes', function () {
    $candidate = candidateWithTwoFactor();
    suggestClaimChange(vaOf($candidate), claimOf($candidate, 'Led a team of four'), 'Led a team of five', ['rationale' => 'The CV says five.']);

    $this->actingAs($candidate)->get(route('knowledge.proposals.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('knowledge/proposals')
            ->where('pendingProposals', 1)
            ->has('pending', 1, fn (Assert $item) => $item
                ->where('rationale', 'The CV says five.')
                ->where('changes', [[
                    'label' => 'Bullet point',
                    'from' => 'Led a team of four',
                    'to' => 'Led a team of five',
                    'base' => null,
                    'diff' => [['op' => 'equal', 'text' => 'Led a team of '], ['op' => 'delete', 'text' => 'four'], ['op' => 'insert', 'text' => 'five']],
                ]])
                ->where('can.decide', true)
                ->etc()));
});

test('a VA sees only their own suggestions', function () {
    $candidate = candidateWithTwoFactor();
    $va = vaOf($candidate);
    $otherVa = vaOf($candidate);
    suggestClaimChange($otherVa, claimOf($candidate, 'Led a team of four'), 'Led a team of five');

    $this->actingAs($va)->get(route('knowledge.proposals.index'))
        ->assertInertia(fn (Assert $page) => $page->has('pending', 0)->where('isReviewer', false));
});

test('a suggestion of another workspace responds 404', function () {
    $candidate = candidateWithTwoFactor();
    $other = candidateWithTwoFactor();
    suggestClaimChange(vaOf($other), claimOf($other, 'Elsewhere'), 'Changed elsewhere');

    $this->actingAs($candidate)->patch(route('knowledge.proposals.approve', onlyProposal($other)))->assertNotFound();
});
