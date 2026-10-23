<?php

namespace App\Domain\Applications;

use App\Domain\Audit\AuditLogger;
use App\Domain\Feedback\FeedbackService;
use App\Domain\Knowledge\AnswerCategory;
use App\Domain\Knowledge\AnswerLibraryEntry;
use App\Domain\Knowledge\AnswerLibraryService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Writes an application's questions and their answers. Candidate and VAs both add questions
 * and write answers; only the candidate approves an answer, which freezes it.
 */
class ApplicationQuestionService
{
    public function __construct(private AuditLogger $audit, private AnswerLibraryService $library, private FeedbackService $feedback) {}

    public function add(JobApplication $application, string $prompt, ?int $wordLimit, ?int $charLimit, User $actor, string $origin = 'manual'): ApplicationQuestion
    {
        return DB::transaction(function () use ($application, $prompt, $wordLimit, $charLimit, $actor, $origin): ApplicationQuestion {
            // Serialise additions per application (Postgres can't lock rows under an aggregate).
            JobApplication::query()->whereKey($application->id)->lockForUpdate()->first();
            $position = (int) ApplicationQuestion::query()->where('job_application_id', $application->id)->max('position') + 1;

            $question = new ApplicationQuestion;
            $question->forceFill([
                'job_application_id' => $application->id,
                'origin' => $origin,
                'prompt_text' => $prompt,
                'word_limit' => $wordLimit,
                'char_limit' => $charLimit,
                'position' => $position,
                'created_by' => $actor->id,
            ])->save();

            $this->audit->record('question.created', $question, ['origin' => $origin], $actor);

            return $question;
        });
    }

    /**
     * @param  list<array{prompt: string, word_limit: ?int, char_limit: ?int}>  $questions
     * @return list<ApplicationQuestion>
     */
    public function addMany(JobApplication $application, array $questions, User $actor, string $origin = 'manual'): array
    {
        return DB::transaction(fn (): array => array_map(
            fn (array $question): ApplicationQuestion => $this->add($application, $question['prompt'], $question['word_limit'], $question['char_limit'], $actor, $origin),
            $questions,
        ));
    }

    /**
     * The application's cover letter question, created on first use.
     */
    public function coverLetter(JobApplication $application, User $actor): ApplicationQuestion
    {
        $existing = ApplicationQuestion::query()
            ->where('job_application_id', $application->id)
            ->where('origin', 'cover_letter')
            ->first();

        return $existing ?? $this->add($application, __('Cover letter'), null, null, $actor, 'cover_letter');
    }

    /**
     * @throws ValidationException while the answer is approved
     */
    public function update(ApplicationQuestion $question, string $prompt, ?int $wordLimit, ?int $charLimit, User $actor): void
    {
        $this->guardNotApproved($question);

        $question->forceFill(['prompt_text' => $prompt, 'word_limit' => $wordLimit, 'char_limit' => $charLimit])->save();

        $this->audit->record('question.updated', $question, [], $actor);
    }

    public function delete(ApplicationQuestion $question, User $actor): void
    {
        DB::transaction(function () use ($question, $actor): void {
            $question->delete();

            $this->audit->record('question.deleted', null, ['prompt' => mb_substr($question->prompt_text, 0, 120)], $actor);
        });
    }

    /**
     * Swap the question with its neighbour above (-1) or below (+1).
     */
    public function move(ApplicationQuestion $question, int $direction): void
    {
        DB::transaction(function () use ($question, $direction): void {
            $neighbour = ApplicationQuestion::query()
                ->where('job_application_id', $question->job_application_id)
                ->where('position', $direction < 0 ? '<' : '>', $question->position)
                ->orderBy('position', $direction < 0 ? 'desc' : 'asc')
                ->lockForUpdate()
                ->first();

            if ($neighbour === null) {
                return;
            }

            [$question->position, $neighbour->position] = [$neighbour->position, $question->position];
            $question->save();
            $neighbour->save();
        });
    }

    /**
     * Save the answer as loaded at `$expectedLockVersion` (0 before the first save). A new
     * version is stored only when the text or its library source changes.
     *
     * @throws ValidationException when the answer is approved or someone saved it in the meantime
     */
    public function saveAnswer(ApplicationQuestion $question, string $body, ?AnswerLibraryEntry $fromLibrary, int $expectedLockVersion, User $actor): AnswerDraft
    {
        return DB::transaction(function () use ($question, $body, $fromLibrary, $expectedLockVersion, $actor): AnswerDraft {
            $draft = AnswerDraft::query()->where('application_question_id', $question->id)->lockForUpdate()->first();

            if ($draft === null) {
                $draft = new AnswerDraft;
                $draft->forceFill(['application_question_id' => $question->id, 'status' => 'draft', 'lock_version' => 0])->save();
            }

            if ($draft->isApproved()) {
                throw ValidationException::withMessages(['body' => __('This answer is approved. Reopen it to make changes.')]);
            }

            if ($draft->lock_version !== $expectedLockVersion) {
                throw ValidationException::withMessages([
                    'lock_version' => __('Someone else saved this answer while you were editing. Reload to see their version, then apply your change again.'),
                ]);
            }

            $draft->load('currentVersion');

            if ($body !== $draft->body() || $fromLibrary?->id !== $draft->currentVersion?->library_entry_id) {
                $version = AnswerDraftVersion::create([
                    'answer_draft_id' => $draft->id,
                    'version_no' => (int) AnswerDraftVersion::query()->where('answer_draft_id', $draft->id)->max('version_no') + 1,
                    'body' => $body,
                    'library_entry_id' => $fromLibrary?->id,
                    'author_type' => 'user',
                    'author_user_id' => $actor->id,
                    'created_via' => $fromLibrary === null ? 'manual' : 'library',
                ]);
                $draft->current_version_id = $version->id;
                $draft->setRelation('currentVersion', $version);
            }

            $draft->lock_version = $expectedLockVersion + 1;
            $draft->save();

            $this->audit->record('answer_draft.saved', $question, ['from_library' => $fromLibrary?->ulid], $actor);

            return $draft;
        });
    }

    /**
     * Approving an edited AI draft also learns from the edit (M10): see FeedbackService.
     *
     * @throws ValidationException for an empty answer, one over the question's limits, or one with AI placeholders left in
     */
    public function approveAnswer(ApplicationQuestion $question, User $actor): void
    {
        $draft = $question->answer?->loadMissing('currentVersion');
        $body = $draft?->body() ?? '';

        if ($draft === null || trim($body) === '') {
            throw ValidationException::withMessages(['body' => __('Write an answer before approving it.')]);
        }

        $problem = $question->limitProblem($body);

        if ($problem !== null) {
            throw ValidationException::withMessages(['body' => __('Too long to approve: :problem', ['problem' => $problem])]);
        }

        if (preg_match('/\[confirm:[^\]]*\]/iu', $body) === 1) {
            throw ValidationException::withMessages(['body' => __('Fill in or remove the [confirm: …] placeholders before approving.')]);
        }

        if ($draft->generation_status === 'checking') {
            throw ValidationException::withMessages(['body' => __('The AI is still labelling this draft\'s claims. Try again in a moment.')]);
        }

        if ($draft->needsClaimReview()) {
            throw ValidationException::withMessages(['body' => __('Check the AI draft\'s unconfirmed claims first: edit them, or mark them as checked under "AI check".')]);
        }

        $draft->forceFill([
            'status' => 'approved',
            'approved_version_id' => $draft->current_version_id,
            'approved_by' => $actor->id,
            'approved_at' => now(),
            'lock_version' => $draft->lock_version + 1,
        ])->save();

        $this->audit->record('answer_draft.approved', $question, [], $actor);

        $this->feedback->capture($draft, $actor, 'approval');
    }

    public function reopenAnswer(ApplicationQuestion $question, User $actor): void
    {
        $draft = $question->answer;

        if ($draft === null || ! $draft->isApproved()) {
            return;
        }

        $draft->forceFill(['status' => 'draft', 'lock_version' => $draft->lock_version + 1])->save();

        $this->audit->record('answer_draft.reopened', $question, [], $actor);
    }

    /**
     * Keep this answer for later applications: a new answer-library entry (approved when the
     * candidate saves it, a draft for a VA).
     *
     * @throws ValidationException for an empty answer
     */
    public function saveToLibrary(ApplicationQuestion $question, AnswerCategory $category, User $actor): AnswerLibraryEntry
    {
        $body = $question->answer?->loadMissing('currentVersion')->body() ?? '';

        if (trim($body) === '') {
            throw ValidationException::withMessages(['body' => __('Write an answer before saving it to the library.')]);
        }

        return $this->library->create($question->prompt_text, $body, $category, [], $actor);
    }

    private function guardNotApproved(ApplicationQuestion $question): void
    {
        if ($question->answer?->isApproved()) {
            throw ValidationException::withMessages(['prompt_text' => __('The answer is approved. Reopen it before changing the question.')]);
        }
    }
}
