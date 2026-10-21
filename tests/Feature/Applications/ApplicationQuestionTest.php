<?php

use App\Domain\Applications\AnswerDraftVersion;
use App\Domain\Applications\ApplicationQuestion;
use App\Domain\Applications\JobApplication;
use App\Domain\Knowledge\AnswerLibraryEntry;
use App\Domain\Knowledge\AnswerStatus;
use App\Models\User;
use Illuminate\Database\QueryException;
use Inertia\Testing\AssertableInertia as Assert;

function trackedApplicationOf(User $candidate): JobApplication
{
    return JobApplication::factory()->forProfile(profileOf($candidate))->create();
}

/**
 * Add a question through the endpoint and return it.
 */
function questionOn(User $actor, JobApplication $application, string $prompt = 'Why do you want to join us?', ?int $wordLimit = null): ApplicationQuestion
{
    test()->actingAs($actor)->post(route('applications.questions.store', $application), ['prompt_text' => $prompt, 'word_limit' => $wordLimit]);

    return inWorkspaceOf($actor, fn () => ApplicationQuestion::query()->where('prompt_text', $prompt)->with('answer.currentVersion')->sole());
}

function answerTo(User $actor, JobApplication $application, ApplicationQuestion $question, string $body, int $lockVersion = 0, ?string $libraryEntry = null): mixed
{
    return test()->actingAs($actor)->put(route('applications.questions.answer', [$application, $question]), [
        'body' => $body,
        'library_entry' => $libraryEntry,
        'lock_version' => $lockVersion,
    ]);
}

function freshQuestion(User $candidate, ApplicationQuestion $question): ApplicationQuestion
{
    return inWorkspaceOf($candidate, fn () => ApplicationQuestion::query()->whereKey($question->id)->with('answer.currentVersion')->sole());
}

describe('questions', function () {
    test('questions are added in form order, from the form or a pasted list with limits', function () {
        $candidate = candidateWithTwoFactor();
        $application = trackedApplicationOf($candidate);
        questionOn($candidate, $application, 'First?');

        $this->actingAs(vaOf($candidate))
            ->post(route('applications.questions.bulk', $application), [
                'questions' => "1. Why us? (300 words)\n\n- Tell us about a project [max 1,500 characters]\nNotice period?",
            ])
            ->assertInertiaFlash('toast.message', 'Added 3 questions.');

        $questions = inWorkspaceOf($candidate, fn () => ApplicationQuestion::query()->orderBy('position')->get());
        expect($questions->pluck('prompt_text')->all())->toBe(['First?', 'Why us?', 'Tell us about a project', 'Notice period?'])
            ->and($questions[1]->word_limit)->toBe(300)
            ->and($questions[2]->char_limit)->toBe(1500)
            ->and($questions[3]->word_limit)->toBeNull();
    });

    test('a pasted list over 50 questions is rejected', function () {
        $candidate = candidateWithTwoFactor();

        $this->actingAs($candidate)
            ->post(route('applications.questions.bulk', trackedApplicationOf($candidate)), [
                'questions' => implode("\n", array_map(fn (int $n) => "Question {$n}?", range(1, 51))),
            ])
            ->assertSessionHasErrors(['questions' => 'Add at most 50 questions at a time.']);
    });

    test('the cover letter is added once', function () {
        $candidate = candidateWithTwoFactor();
        $application = trackedApplicationOf($candidate);

        $this->actingAs($candidate)->post(route('applications.questions.cover-letter', $application))->assertRedirect();
        $this->actingAs($candidate)->post(route('applications.questions.cover-letter', $application));

        expect(inWorkspaceOf($candidate, fn () => ApplicationQuestion::query()->where('origin', 'cover_letter')->count()))->toBe(1);
    });

    test('questions move up and down and can be deleted', function () {
        $candidate = candidateWithTwoFactor();
        $application = trackedApplicationOf($candidate);
        questionOn($candidate, $application, 'A?');
        $second = questionOn($candidate, $application, 'B?');

        $this->actingAs($candidate)->patch(route('applications.questions.move', [$application, $second]), ['direction' => 'up']);
        expect(inWorkspaceOf($candidate, fn () => ApplicationQuestion::query()->orderBy('position')->pluck('prompt_text')->all()))->toBe(['B?', 'A?']);

        $this->actingAs($candidate)->delete(route('applications.questions.destroy', [$application, $second]));
        expect(inWorkspaceOf($candidate, fn () => ApplicationQuestion::query()->pluck('prompt_text')->all()))->toBe(['A?']);
    });

    test('a question of another application is not reachable through this one', function () {
        $candidate = candidateWithTwoFactor();
        $question = questionOn($candidate, trackedApplicationOf($candidate));

        answerTo($candidate, trackedApplicationOf($candidate), $question, 'Text')->assertNotFound();
    });
});

describe('answers', function () {
    test('each change to the answer is a new version; saving the same text again is not', function () {
        $candidate = candidateWithTwoFactor();
        $application = trackedApplicationOf($candidate);
        $question = questionOn($candidate, $application);

        answerTo(vaOf($candidate), $application, $question, 'First draft')->assertSessionHasNoErrors();
        answerTo($candidate, $application, $question, 'Second draft', 1);
        answerTo($candidate, $application, $question, 'Second draft', 2);

        $question = freshQuestion($candidate, $question);
        expect($question->answer?->body())->toBe('Second draft')
            ->and($question->answer?->status)->toBe('draft')
            ->and(inWorkspaceOf($candidate, fn () => AnswerDraftVersion::query()->orderBy('version_no')->pluck('body')->all()))->toBe(['First draft', 'Second draft']);
    });

    test('an answer saved from an outdated version is rejected', function () {
        $candidate = candidateWithTwoFactor();
        $application = trackedApplicationOf($candidate);
        $question = questionOn($candidate, $application);
        answerTo($candidate, $application, $question, 'Mine');

        answerTo(vaOf($candidate), $application, $question, 'Stale', 0)->assertSessionHasErrors('lock_version');
    });

    test('inserting from the library records where the text came from', function () {
        $candidate = candidateWithTwoFactor();
        $application = trackedApplicationOf($candidate);
        $question = questionOn($candidate, $application);
        $entry = AnswerLibraryEntry::factory()->forProfile(profileOf($candidate))->approved($candidate)->answering('Because of your mission.')->create(['question' => 'Why us?']);

        answerTo($candidate, $application, $question, 'Because of your mission.', 0, $entry->ulid);

        $version = freshQuestion($candidate, $question)->answer?->currentVersion;
        expect($version?->library_entry_id)->toBe($entry->id)
            ->and($version?->created_via)->toBe('library');

        $this->actingAs($candidate)->get(route('applications.questions.index', $application))
            ->assertInertia(fn (Assert $page) => $page
                ->component('applications/questions')
                ->where('questions.0.answer.from_library', 'Why us?')
                ->where('library.0.ulid', $entry->ulid));
    });

    test('a library entry of another workspace cannot be cited', function () {
        $candidate = candidateWithTwoFactor();
        $application = trackedApplicationOf($candidate);
        $question = questionOn($candidate, $application);
        $other = candidateWithTwoFactor();
        $foreign = AnswerLibraryEntry::factory()->forProfile(profileOf($other))->approved($other)->answering('Theirs')->create();

        answerTo($candidate, $application, $question, 'Theirs', 0, $foreign->ulid)->assertSessionHasErrors('library_entry');
    });

    test('the answer is saved to the library: approved for the candidate, a draft for a VA', function () {
        $candidate = candidateWithTwoFactor();
        $application = trackedApplicationOf($candidate);
        $question = questionOn($candidate, $application, 'What is your notice period?');
        answerTo($candidate, $application, $question, 'One month.');

        $this->actingAs(vaOf($candidate))
            ->post(route('applications.questions.save-to-library', [$application, $question]), ['category' => 'availability'])
            ->assertInertiaFlash('toast.message', 'Saved to the answer library as a draft. The candidate will approve it.');

        $entry = inWorkspaceOf($candidate, fn () => AnswerLibraryEntry::query()->with('currentRevision')->sole());
        expect($entry->question)->toBe('What is your notice period?')
            ->and($entry->body())->toBe('One month.')
            ->and($entry->status)->toBe(AnswerStatus::Draft);
    });
});

describe('approve', function () {
    test('the candidate approves an answer, which freezes it and the question until reopened', function () {
        $candidate = candidateWithTwoFactor();
        $va = vaOf($candidate);
        $application = trackedApplicationOf($candidate);
        $question = questionOn($candidate, $application);
        answerTo($va, $application, $question, 'Ready.');

        $this->actingAs($va)->patch(route('applications.questions.approve', [$application, $question]))->assertForbidden();
        $this->actingAs($candidate)->patch(route('applications.questions.approve', [$application, $question]))->assertSessionHasNoErrors();

        $answer = freshQuestion($candidate, $question)->answer;
        expect($answer?->status)->toBe('approved')
            ->and($answer?->approved_version_id)->toBe($answer?->current_version_id);

        answerTo($va, $application, $question, 'Changed', $answer->lock_version)->assertSessionHasErrors('body');
        $this->actingAs($va)
            ->put(route('applications.questions.update', [$application, $question]), ['prompt_text' => 'Changed question?'])
            ->assertSessionHasErrors('prompt_text');

        $this->actingAs($va)->patch(route('applications.questions.reopen', [$application, $question]))->assertForbidden();
        $this->actingAs($candidate)->patch(route('applications.questions.reopen', [$application, $question]));
        answerTo($va, $application, $question, 'Changed', freshQuestion($candidate, $question)->answer->lock_version)->assertSessionHasNoErrors();
    });

    test('an answer over its word limit or an empty one cannot be approved', function () {
        $candidate = candidateWithTwoFactor();
        $application = trackedApplicationOf($candidate);
        $question = questionOn($candidate, $application, 'In three words?', 3);

        $this->actingAs($candidate)
            ->patch(route('applications.questions.approve', [$application, $question]))
            ->assertSessionHasErrors(['body' => 'Write an answer before approving it.']);

        answerTo($candidate, $application, $question, 'One two three four');
        $this->actingAs($candidate)
            ->patch(route('applications.questions.approve', [$application, $question]))
            ->assertSessionHasErrors(['body' => 'Too long to approve: 4 words; the limit is 3.']);

        expect(freshQuestion($candidate, $question)->answer?->status)->toBe('draft');
    });
});

test('answer versions cannot be edited once written', function () {
    $candidate = candidateWithTwoFactor();
    $application = trackedApplicationOf($candidate);
    answerTo($candidate, $application, questionOn($candidate, $application), 'Original');

    inWorkspaceOf($candidate, fn () => AnswerDraftVersion::query()->update(['body' => 'Tampered']));
})->throws(QueryException::class, 'append-only');

test('the questions of another workspace\'s application respond 404', function () {
    $owner = candidateWithTwoFactor();
    $application = trackedApplicationOf($owner);

    $this->actingAs(candidateWithTwoFactor())->get(route('applications.questions.index', $application))->assertNotFound();
});

test('the application page counts questions, answers and approvals', function () {
    $candidate = candidateWithTwoFactor();
    $application = trackedApplicationOf($candidate);
    $answered = questionOn($candidate, $application, 'One?');
    questionOn($candidate, $application, 'Two?');
    answerTo($candidate, $application, $answered, 'Yes.');
    $this->actingAs($candidate)->post(route('applications.questions.cover-letter', $application));

    $this->actingAs($candidate)->get(route('applications.show', $application))
        ->assertInertia(fn (Assert $page) => $page
            ->where('questionsSummary.total', 3)
            ->where('questionsSummary.answered', 1)
            ->where('questionsSummary.approved', 0)
            ->where('questionsSummary.has_cover_letter', true));
});
