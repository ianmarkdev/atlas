<?php

use App\Domain\Audit\AuditEvent;
use App\Domain\Knowledge\Claim;
use App\Domain\Knowledge\ClaimKind;
use App\Domain\Knowledge\ClaimRevision;
use App\Domain\Knowledge\ClaimSource;
use App\Domain\Knowledge\ClaimStatus;
use App\Domain\Knowledge\KnowledgeProposal;
use App\Domain\Knowledge\Project;
use Illuminate\Database\QueryException;
use Inertia\Testing\AssertableInertia as Assert;

describe('create', function () {
    test('a claim the candidate adds is verified, with revision 1 and a confirmation source', function () {
        $candidate = candidateWithTwoFactor();
        $employment = employmentOf($candidate);

        $this->actingAs($candidate)
            ->post(route('knowledge.claims.store'), [
                'subject' => "employment:{$employment->ulid}",
                'kind' => 'achievement',
                'statement' => 'Cut the nightly invoice run from 4 hours to 20 minutes.',
            ])
            ->assertRedirect(route('knowledge.index'));

        [$claim, $source, $event] = inWorkspaceOf($candidate, fn () => [
            Claim::query()->with('currentRevision')->sole(),
            ClaimSource::query()->sole(),
            AuditEvent::query()->where('action', 'claim.created')->sole(),
        ]);
        expect($claim->status)->toBe(ClaimStatus::Verified)
            ->and($claim->subject_type)->toBe('employment')
            ->and($claim->subject_id)->toBe($employment->id)
            ->and($claim->currentRevision?->revision_no)->toBe(1)
            ->and($claim->statement())->toBe('Cut the nightly invoice run from 4 hours to 20 minutes.')
            ->and($source->source_type)->toBe('user_confirmation')
            ->and($source->claim_revision_id)->toBe($claim->current_revision_id)
            ->and($source->confirmed_by)->toBe($candidate->id)
            ->and($event->subject_id)->toBe($claim->id);
    });

    test('a claim a VA adds stays proposed with no confirmation', function () {
        $candidate = candidateWithTwoFactor();

        $this->actingAs(vaOf($candidate))->post(route('knowledge.claims.store'), [
            'subject' => 'general',
            'kind' => 'skill',
            'statement' => 'Fluent in PostgreSQL query tuning',
        ]);

        [$claim, $sources] = inWorkspaceOf($candidate, fn () => [Claim::query()->sole(), ClaimSource::query()->count()]);
        expect($claim->status)->toBe(ClaimStatus::Proposed)
            ->and($claim->subject_type)->toBeNull()
            ->and($sources)->toBe(0);
    });

    test('the same statement with different case and punctuation is rejected as a duplicate', function () {
        $candidate = candidateWithTwoFactor();
        claimOf($candidate, 'Led a team of four developers.');

        $this->actingAs($candidate)
            ->post(route('knowledge.claims.store'), ['subject' => 'general', 'kind' => 'achievement', 'statement' => 'led a team of four developers'])
            ->assertSessionHasErrors(['statement' => 'You already have this claim.']);
    });

    test('a near duplicate about the same subject is flagged and saved only when confirmed', function () {
        $candidate = candidateWithTwoFactor();
        $employment = employmentOf($candidate);
        claimOf($candidate, 'Migrated the billing platform from cron jobs to queued jobs', $employment);
        $input = [
            'subject' => "employment:{$employment->ulid}",
            'kind' => 'achievement',
            'statement' => 'Migrated the billing platform from cron jobs to queue jobs',
        ];

        $this->actingAs($candidate)
            ->post(route('knowledge.claims.store'), $input)
            ->assertSessionHasErrors(['statement', 'near_duplicate']);

        $this->actingAs($candidate)
            ->post(route('knowledge.claims.store'), [...$input, 'allow_near_duplicate' => '1'])
            ->assertSessionHasNoErrors();

        expect(inWorkspaceOf($candidate, fn () => Claim::query()->count()))->toBe(2);
    });

    test('a claim cannot be about another workspace\'s employment', function () {
        $candidate = candidateWithTwoFactor();
        $foreign = employmentOf(candidateWithTwoFactor());

        $this->actingAs($candidate)
            ->post(route('knowledge.claims.store'), ['subject' => "employment:{$foreign->ulid}", 'kind' => 'achievement', 'statement' => 'Sneaky'])
            ->assertSessionHasErrors(['subject' => 'Choose an employment or project from your record.']);
    });

    test('a claim cannot be about an archived project', function () {
        $candidate = candidateWithTwoFactor();
        $archived = Project::factory()->forProfile(profileOf($candidate))->archived()->create();

        $this->actingAs($candidate)
            ->post(route('knowledge.claims.store'), ['subject' => "project:{$archived->ulid}", 'kind' => 'achievement', 'statement' => 'Late'])
            ->assertSessionHasErrors('subject');
    });

    test('a statement over 1,000 characters is rejected', function () {
        $this->actingAs(candidateWithTwoFactor())
            ->post(route('knowledge.claims.store'), ['subject' => 'general', 'kind' => 'other', 'statement' => str_repeat('a', 1001)])
            ->assertSessionHasErrors(['statement' => 'The statement field must not be greater than 1000 characters.']);
    });
});

describe('revise', function () {
    test('editing a verified claim appends a confirmed revision and keeps the old one', function () {
        $candidate = candidateWithTwoFactor();
        $claim = claimOf($candidate, 'Led a team of four');

        $this->actingAs($candidate)
            ->put(route('knowledge.claims.update', $claim), [
                'kind' => 'achievement',
                'statement' => 'Led a team of five',
                'change_reason' => 'Corrected team size',
                'lock_version' => 0,
            ])
            ->assertRedirect(route('knowledge.index'));

        $claim->refresh()->load('currentRevision');
        [$revisions, $confirmedRevisionIds] = inWorkspaceOf($candidate, fn () => [
            ClaimRevision::query()->where('claim_id', $claim->id)->orderBy('revision_no')->pluck('statement')->all(),
            ClaimSource::query()->where('claim_id', $claim->id)->pluck('claim_revision_id')->all(),
        ]);
        expect($revisions)->toBe(['Led a team of four', 'Led a team of five'])
            ->and($claim->status)->toBe(ClaimStatus::Verified)
            ->and($claim->currentRevision?->change_reason)->toBe('Corrected team size')
            ->and($confirmedRevisionIds)->toContain($claim->current_revision_id)
            ->and($claim->lock_version)->toBe(1);
    });

    test('changing only the kind does not add a revision', function () {
        $candidate = candidateWithTwoFactor();
        $claim = claimOf($candidate, 'Laravel');

        $this->actingAs($candidate)->put(route('knowledge.claims.update', $claim), [
            'kind' => 'skill',
            'statement' => 'Laravel',
            'lock_version' => 0,
        ]);

        expect($claim->refresh()->kind)->toBe(ClaimKind::Skill)
            ->and(inWorkspaceOf($candidate, fn () => ClaimRevision::query()->where('claim_id', $claim->id)->count()))->toBe(1);
    });

    test('an edit based on an outdated version is rejected', function () {
        $candidate = candidateWithTwoFactor();
        $claim = claimOf($candidate, 'Original statement');
        $claim->forceFill(['lock_version' => 2])->save();

        $this->actingAs($candidate)
            ->put(route('knowledge.claims.update', $claim), ['kind' => 'achievement', 'statement' => 'Overwrite', 'lock_version' => 1])
            ->assertSessionHasErrors('lock_version');

        expect($claim->refresh()->load('currentRevision')->statement())->toBe('Original statement');
    });

    test('a VA\'s edit of a verified claim becomes a suggestion and leaves the claim unchanged', function () {
        $candidate = candidateWithTwoFactor();
        $claim = claimOf($candidate, 'Original statement');

        $this->actingAs(vaOf($candidate))
            ->put(route('knowledge.claims.update', $claim), ['kind' => 'achievement', 'statement' => 'Changed', 'lock_version' => 0])
            ->assertRedirect(route('knowledge.index'));

        expect($claim->refresh()->load('currentRevision')->statement())->toBe('Original statement')
            ->and(inWorkspaceOf($candidate, fn () => KnowledgeProposal::query()->sole()->payload['statement']))->toBe('Changed');
    });

    test('a VA edits a proposed claim', function () {
        $candidate = candidateWithTwoFactor();
        $claim = Claim::factory()->forProfile(profileOf($candidate))->stating('Draft wording')->create();

        $this->actingAs(vaOf($candidate))
            ->put(route('knowledge.claims.update', $claim), ['kind' => 'achievement', 'statement' => 'Better wording', 'lock_version' => 0])
            ->assertRedirect(route('knowledge.index'));

        expect($claim->refresh()->load('currentRevision'))
            ->statement()->toBe('Better wording')
            ->status->toBe(ClaimStatus::Proposed);
    });

    test('a claim of another workspace responds 404', function () {
        $candidate = candidateWithTwoFactor();
        $foreign = claimOf(candidateWithTwoFactor(), 'Not yours');

        $this->actingAs($candidate)->get(route('knowledge.claims.edit', $foreign))->assertNotFound();
    });
});

describe('status changes', function () {
    test('the candidate verifies a proposed claim, confirming its current revision', function () {
        $candidate = candidateWithTwoFactor();
        $claim = Claim::factory()->forProfile(profileOf($candidate))->stating('Proposed by a VA')->create();

        $this->actingAs($candidate)->patch(route('knowledge.claims.verify', $claim))->assertRedirect();

        $claim->refresh();
        expect($claim->status)->toBe(ClaimStatus::Verified)
            ->and(inWorkspaceOf($candidate, fn () => ClaimSource::query()->where('claim_revision_id', $claim->current_revision_id)->value('source_type')))
            ->toBe('user_confirmation');
    });

    test('the candidate rejects a proposed claim and can restore it as verified', function () {
        $candidate = candidateWithTwoFactor();
        $claim = Claim::factory()->forProfile(profileOf($candidate))->stating('Doubtful')->create();

        $this->actingAs($candidate)->patch(route('knowledge.claims.reject', $claim));
        expect($claim->refresh()->status)->toBe(ClaimStatus::Rejected);

        $this->actingAs($candidate)->patch(route('knowledge.claims.restore', $claim));
        expect($claim->refresh()->status)->toBe(ClaimStatus::Verified);
    });

    test('restoring is refused while a live claim says the same thing', function () {
        $candidate = candidateWithTwoFactor();
        $archived = Claim::factory()->forProfile(profileOf($candidate))->stating('Led a team of four')->archived()->create();
        claimOf($candidate, 'Led a team of four');

        $this->actingAs($candidate)
            ->patch(route('knowledge.claims.restore', $archived))
            ->assertSessionHasErrors(['statement' => 'You already have this claim.']);

        expect($archived->refresh()->status)->toBe(ClaimStatus::Archived);
    });

    test('a VA cannot verify or reject a claim', function () {
        $candidate = candidateWithTwoFactor();
        $va = vaOf($candidate);
        $claim = Claim::factory()->forProfile(profileOf($candidate))->stating('Proposed')->create();

        $this->actingAs($va)->patch(route('knowledge.claims.verify', $claim))->assertForbidden();
        $this->actingAs($va)->patch(route('knowledge.claims.reject', $claim))->assertForbidden();

        expect($claim->refresh()->status)->toBe(ClaimStatus::Proposed);
    });
});

describe('bulk add', function () {
    test('each pasted line becomes a claim; list markers, blank lines and duplicates are skipped', function () {
        $candidate = candidateWithTwoFactor();
        $employment = employmentOf($candidate);
        claimOf($candidate, 'Already known', $employment);

        $this->actingAs($candidate)
            ->post(route('knowledge.claims.bulk.store'), [
                'subject' => "employment:{$employment->ulid}",
                'kind' => 'achievement',
                'statements' => "- Led a team of four\n\n• Shipped the billing rewrite\n2. Already known\n* led a team of four.",
            ])
            ->assertRedirect(route('knowledge.index'))
            ->assertInertiaFlash('toast.message', 'Added 2 bullet points. Skipped 2 duplicates.');

        $statements = inWorkspaceOf($candidate, fn () => Claim::query()->with('currentRevision')->get()->map->statement()->sort()->values()->all());
        expect($statements)->toBe(['Already known', 'Led a team of four', 'Shipped the billing rewrite']);
    });

    test('more than 100 lines are rejected', function () {
        $this->actingAs(candidateWithTwoFactor())
            ->post(route('knowledge.claims.bulk.store'), [
                'subject' => 'general',
                'kind' => 'skill',
                'statements' => implode("\n", array_map(fn (int $n) => "Skill {$n}", range(1, 101))),
            ])
            ->assertSessionHasErrors(['statements' => 'Add at most 100 claims at a time.']);
    });
});

test('claim revisions cannot be edited once written', function () {
    $candidate = candidateWithTwoFactor();
    $claim = claimOf($candidate, 'History');

    inWorkspaceOf($candidate, fn () => ClaimRevision::query()->whereKey($claim->current_revision_id)->update(['statement' => 'Tampered']));
})->throws(QueryException::class, 'claim_revisions is append-only');

test('the overview nests claims under their employment and lists general claims, hiding archived ones', function () {
    $candidate = candidateWithTwoFactor();
    $employment = employmentOf($candidate);
    $jobClaim = claimOf($candidate, 'Built the billing API', $employment);
    $generalClaim = claimOf($candidate, 'Speaks German');
    Claim::factory()->forProfile(profileOf($candidate))->stating('Old claim')->archived()->create();

    $this->actingAs($candidate)->get(route('knowledge.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('employments.0.claims', 1)
            ->where('employments.0.claims.0.ulid', $jobClaim->ulid)
            ->where('employments.0.claims.0.statement', 'Built the billing API')
            ->has('generalClaims', 1)
            ->where('generalClaims.0.ulid', $generalClaim->ulid));
});
