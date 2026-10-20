<?php

use App\Domain\Knowledge\AnswerCategory;
use App\Domain\Knowledge\AnswerLibraryEntry;
use App\Domain\Knowledge\AnswerLibraryRevision;
use App\Domain\Knowledge\AnswerStatus;
use App\Domain\Knowledge\KnowledgeProposal;
use App\Domain\Knowledge\ProposalStatus;
use App\Models\User;
use Illuminate\Database\QueryException;
use Inertia\Testing\AssertableInertia as Assert;

function answerOf(User $candidate, string $question, string $body, array $claimUlids = []): AnswerLibraryEntry
{
    return AnswerLibraryEntry::factory()
        ->forProfile(profileOf($candidate))
        ->approved($candidate)
        ->answering($body, $claimUlids)
        ->create(['question' => $question]);
}

function onlyAnswer(User $candidate): AnswerLibraryEntry
{
    return inWorkspaceOf($candidate, fn () => AnswerLibraryEntry::query()->with('currentRevision')->sole());
}

describe('create', function () {
    test('an answer the candidate adds is approved, with revision 1 and its evidence', function () {
        $candidate = candidateWithTwoFactor();
        $claim = claimOf($candidate, 'Led a team of four');

        $this->actingAs($candidate)
            ->post(route('knowledge.answers.store'), [
                'question' => 'Tell us about a time you led a team.',
                'category' => 'behavioural',
                'body' => "At Acme I led four engineers.\nWe shipped on time.",
                'claims' => [$claim->ulid],
            ])
            ->assertRedirect(route('knowledge.answers.index'))
            ->assertInertiaFlash('toast.message', 'Answer saved.');

        $answer = onlyAnswer($candidate);
        expect($answer->status)->toBe(AnswerStatus::Approved)
            ->and($answer->approved_by)->toBe($candidate->id)
            ->and($answer->category)->toBe(AnswerCategory::Behavioural)
            ->and($answer->currentRevision?->revision_no)->toBe(1)
            ->and($answer->body())->toBe("At Acme I led four engineers.\nWe shipped on time.")
            ->and($answer->claimUlids())->toBe([$claim->ulid]);
    });

    test('an answer a VA adds is a draft until the candidate approves it', function () {
        $candidate = candidateWithTwoFactor();
        $va = vaOf($candidate);

        $this->actingAs($va)->post(route('knowledge.answers.store'), [
            'question' => 'What is your notice period?',
            'category' => 'availability',
            'body' => 'One month.',
        ])->assertInertiaFlash('toast.message', 'Answer saved as a draft. The candidate will approve it.');

        $answer = onlyAnswer($candidate);
        expect($answer->status)->toBe(AnswerStatus::Draft)
            ->and($answer->approved_by)->toBeNull();

        $this->actingAs($va)->patch(route('knowledge.answers.approve', $answer))->assertForbidden();
        $this->actingAs($candidate)->patch(route('knowledge.answers.approve', $answer))->assertRedirect();

        expect($answer->refresh()->status)->toBe(AnswerStatus::Approved);
    });

    test('evidence must be a live bullet point of the same workspace', function () {
        $candidate = candidateWithTwoFactor();
        $foreignClaim = claimOf(candidateWithTwoFactor(), 'Someone else\'s fact');

        $this->actingAs($candidate)
            ->post(route('knowledge.answers.store'), [
                'question' => 'Why us?',
                'category' => 'motivation',
                'body' => 'Because.',
                'claims' => [$foreignClaim->ulid],
            ])
            ->assertSessionHasErrors('claims.0');

        expect(inWorkspaceOf($candidate, fn () => AnswerLibraryEntry::query()->count()))->toBe(0);
    });

    test('an answer over 5,000 characters is rejected', function () {
        $this->actingAs(candidateWithTwoFactor())
            ->post(route('knowledge.answers.store'), [
                'question' => 'Why us?',
                'category' => 'motivation',
                'body' => str_repeat('a', 5001),
            ])
            ->assertSessionHasErrors('body');
    });
});

describe('edit', function () {
    test('changing the text appends a revision and keeps the old one', function () {
        $candidate = candidateWithTwoFactor();
        $answer = answerOf($candidate, 'Why us?', 'Because.');

        $this->actingAs($candidate)
            ->put(route('knowledge.answers.update', $answer), [
                'question' => 'Why do you want to work with us?',
                'category' => 'motivation',
                'body' => 'Because of your mission.',
                'change_reason' => 'More specific',
                'lock_version' => 0,
            ])
            ->assertRedirect(route('knowledge.answers.index'));

        $answer = onlyAnswer($candidate);
        $revisions = inWorkspaceOf($candidate, fn () => AnswerLibraryRevision::query()->orderBy('revision_no')->pluck('body')->all());
        expect($answer->question)->toBe('Why do you want to work with us?')
            ->and($answer->lock_version)->toBe(1)
            ->and($answer->currentRevision?->revision_no)->toBe(2)
            ->and($answer->currentRevision?->change_reason)->toBe('More specific')
            ->and($revisions)->toBe(['Because.', 'Because of your mission.']);
    });

    test('changing only the question or category does not add a revision', function () {
        $candidate = candidateWithTwoFactor();
        $answer = answerOf($candidate, 'Why us?', 'Because.');

        $this->actingAs($candidate)->put(route('knowledge.answers.update', $answer), [
            'question' => 'Why this company?',
            'category' => 'other',
            'body' => 'Because.',
            'lock_version' => 0,
        ]);

        expect(onlyAnswer($candidate)->currentRevision?->revision_no)->toBe(1);
    });

    test('an edit based on an outdated version is rejected', function () {
        $candidate = candidateWithTwoFactor();
        $answer = answerOf($candidate, 'Why us?', 'Because.');
        $answer->forceFill(['lock_version' => 3])->save();

        $this->actingAs($candidate)
            ->put(route('knowledge.answers.update', $answer), [
                'question' => 'Why us?',
                'category' => 'motivation',
                'body' => 'Changed.',
                'lock_version' => 2,
            ])
            ->assertSessionHasErrors('lock_version');

        expect(onlyAnswer($candidate)->body())->toBe('Because.');
    });

    test('a VA edits a draft directly', function () {
        $candidate = candidateWithTwoFactor();
        $draft = AnswerLibraryEntry::factory()->forProfile(profileOf($candidate))->answering('Draft text')->create();

        $this->actingAs(vaOf($candidate))->put(route('knowledge.answers.update', $draft), [
            'question' => $draft->question,
            'category' => 'motivation',
            'body' => 'Better draft text',
            'lock_version' => 0,
        ]);

        expect(onlyAnswer($candidate)->body())->toBe('Better draft text')
            ->and(inWorkspaceOf($candidate, fn () => KnowledgeProposal::query()->count()))->toBe(0);
    });

    test('a VA\'s edit of an approved answer becomes a suggestion; approving it applies the change', function () {
        $candidate = candidateWithTwoFactor();
        $answer = answerOf($candidate, 'Why us?', 'Because.');

        $this->actingAs(vaOf($candidate))
            ->get(route('knowledge.answers.edit', $answer))
            ->assertInertia(fn (Assert $page) => $page->where('proposing', true));

        $this->actingAs(vaOf($candidate))->put(route('knowledge.answers.update', $answer), [
            'question' => 'Why us?',
            'category' => 'motivation',
            'body' => 'Because of your product.',
            'lock_version' => 0,
            'rationale' => 'Matches the cover letter.',
        ]);

        expect(onlyAnswer($candidate)->body())->toBe('Because.');
        $proposal = inWorkspaceOf($candidate, fn () => KnowledgeProposal::query()->sole());
        expect($proposal->proposal_type)->toBe('revise_library_answer');

        $this->actingAs($candidate)->patch(route('knowledge.proposals.approve', $proposal));

        $answer = onlyAnswer($candidate);
        expect($answer->body())->toBe('Because of your product.')
            ->and($answer->currentRevision?->created_via)->toBe("proposal:{$proposal->id}")
            ->and($proposal->refresh()->status)->toBe(ProposalStatus::Approved);
    });

    test('the edit page lists earlier versions newest first, marking ones from an approved suggestion', function () {
        $candidate = candidateWithTwoFactor();
        $answer = answerOf($candidate, 'Why us?', 'Because.');
        $this->actingAs($candidate)->put(route('knowledge.answers.update', $answer), [
            'question' => 'Why us?',
            'category' => 'motivation',
            'body' => 'Because of your mission.',
            'change_reason' => 'More specific',
            'lock_version' => 0,
        ]);

        $this->actingAs($candidate)->get(route('knowledge.answers.edit', $answer))
            ->assertInertia(fn (Assert $page) => $page
                ->has('history', 2)
                ->where('history.0.revision_no', 2)
                ->where('history.0.text', 'Because of your mission.')
                ->where('history.0.change_reason', 'More specific')
                ->where('history.0.author', $candidate->name)
                ->where('history.0.from_suggestion', false)
                ->where('history.1.text', 'Because.'));
    });

    test('an answer of another workspace responds 404', function () {
        $foreign = answerOf(candidateWithTwoFactor(), 'Why us?', 'Because.');

        $this->actingAs(candidateWithTwoFactor())->get(route('knowledge.answers.edit', $foreign))->assertNotFound();
    });
});

describe('archive and restore', function () {
    test('the candidate archives and restores an answer; a VA cannot', function () {
        $candidate = candidateWithTwoFactor();
        $va = vaOf($candidate);
        $answer = answerOf($candidate, 'Why us?', 'Because.');

        $this->actingAs($va)->patch(route('knowledge.answers.archive', $answer))->assertForbidden();
        $this->actingAs($candidate)->patch(route('knowledge.answers.archive', $answer))->assertRedirect();
        expect($answer->refresh()->status)->toBe(AnswerStatus::Archived);

        $this->actingAs($va)->patch(route('knowledge.answers.restore', $answer))->assertForbidden();
        $this->actingAs($candidate)->patch(route('knowledge.answers.restore', $answer))->assertRedirect();
        expect($answer->refresh()->status)->toBe(AnswerStatus::Approved);
    });
});

describe('paste a list', function () {
    test('each block separated by --- becomes an answer; the first line is the question', function () {
        $candidate = candidateWithTwoFactor();

        $this->actingAs($candidate)
            ->post(route('knowledge.answers.bulk.store'), [
                'category' => 'other',
                'pairs' => "Why us?\nBecause of your mission.\nAnd the team.\n\n---\n\nNotice period?\nOne month.\n---\n",
            ])
            ->assertRedirect(route('knowledge.answers.index'))
            ->assertInertiaFlash('toast.message', 'Added 2 answers.');

        $answers = inWorkspaceOf($candidate, fn () => AnswerLibraryEntry::query()->with('currentRevision')->orderBy('id')->get());
        expect($answers->pluck('question')->all())->toBe(['Why us?', 'Notice period?'])
            ->and($answers->map->body()->all())->toBe(["Because of your mission.\nAnd the team.", 'One month.'])
            ->and($answers->pluck('status')->unique()->all())->toBe([AnswerStatus::Approved]);
    });

    test('a question without an answer is rejected and nothing is saved', function () {
        $candidate = candidateWithTwoFactor();

        $this->actingAs($candidate)
            ->post(route('knowledge.answers.bulk.store'), [
                'category' => 'other',
                'pairs' => "Why us?\nBecause.\n---\nNotice period?",
            ])
            ->assertSessionHasErrors(['pairs' => 'Answer 2 has a question but no answer.']);

        expect(inWorkspaceOf($candidate, fn () => AnswerLibraryEntry::query()->count()))->toBe(0);
    });

    test('more than 50 pairs are rejected', function () {
        $pairs = implode("\n---\n", array_map(fn (int $n) => "Question {$n}\nAnswer {$n}", range(1, 51)));

        $this->actingAs(candidateWithTwoFactor())
            ->post(route('knowledge.answers.bulk.store'), ['category' => 'other', 'pairs' => $pairs])
            ->assertSessionHasErrors(['pairs' => 'Add at most 50 answers at a time.']);
    });
});

test('the list searches question and answer text, filters by category and hides archived answers', function () {
    $candidate = candidateWithTwoFactor();
    $mission = answerOf($candidate, 'Why us?', 'Your mission on climate.');
    $notice = answerOf($candidate, 'Notice period?', 'One month.');
    $notice->forceFill(['category' => AnswerCategory::Availability])->save();
    $archived = answerOf($candidate, 'Old question', 'Climate too.');
    $archived->forceFill(['status' => AnswerStatus::Archived])->save();

    $this->actingAs($candidate)->get(route('knowledge.answers.index', ['q' => 'climate']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('knowledge/answers')
            ->has('answers', 1)
            ->where('answers.0.ulid', $mission->ulid));

    $this->actingAs($candidate)->get(route('knowledge.answers.index', ['category' => 'availability']))
        ->assertInertia(fn (Assert $page) => $page->has('answers', 1)->where('answers.0.ulid', $notice->ulid));

    $this->actingAs($candidate)->get(route('knowledge.answers.index', ['archived' => 1]))
        ->assertInertia(fn (Assert $page) => $page->has('answers', 1)->where('answers.0.ulid', $archived->ulid));
});

test('answer revisions cannot be edited once written', function () {
    $candidate = candidateWithTwoFactor();
    $answer = answerOf($candidate, 'Why us?', 'Because.');

    inWorkspaceOf($candidate, fn () => AnswerLibraryRevision::query()->whereKey($answer->current_revision_id)->update(['body' => 'Tampered']));
})->throws(QueryException::class, 'append-only');
