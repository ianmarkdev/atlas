<?php

namespace App\Http\Controllers\Applications;

use App\Domain\Applications\AiAnswerService;
use App\Domain\Applications\AnswerDraftVersion;
use App\Domain\Applications\ApplicationQuestion;
use App\Domain\Applications\ApplicationQuestionService;
use App\Domain\Applications\JobApplication;
use App\Domain\Knowledge\AnswerCategory;
use App\Domain\Knowledge\AnswerLibraryEntry;
use App\Domain\Knowledge\AnswerStatus;
use App\Domain\Knowledge\ClaimSubject;
use App\Domain\Shared\PlainText;
use App\Http\Controllers\Controller;
use App\Http\Requests\Applications\BulkStoreQuestionsRequest;
use App\Http\Requests\Applications\SaveAnswerDraftRequest;
use App\Http\Requests\Applications\SaveQuestionRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The questions of one application form and their answers (M7a slice C), written by hand or
 * inserted from the answer library. The cover letter is a question too.
 */
class ApplicationQuestionController extends Controller
{
    public function __construct(private ApplicationQuestionService $questions, private AiAnswerService $aiAnswers) {}

    public function index(Request $request, JobApplication $application): Response
    {
        $application->load(['posting', 'questions.answer.currentVersion.libraryEntry', 'questions.answer.currentVersion.resumeVersion:id,version_no', 'questions.answer.currentVersion.reviews.claim:id,status', 'questions.answer.approver:id,name', 'questions.application']);
        $user = $request->user();

        $histories = AnswerDraftVersion::query()
            ->whereIn('answer_draft_id', $application->questions->pluck('answer.id')->filter())
            ->with('author:id,name')
            ->orderByDesc('version_no')
            ->get()
            ->groupBy('answer_draft_id');

        $library = AnswerLibraryEntry::query()
            ->whereIn('status', [AnswerStatus::Approved, AnswerStatus::Draft])
            ->with('currentRevision')
            ->orderBy('category')
            ->orderBy('question')
            ->get();

        return Inertia::render('applications/questions', [
            'application' => [
                'ulid' => $application->ulid,
                'company_name' => $application->company_name,
                'role_title' => $application->role_title,
                'job_description' => $application->jobDescription(),
            ],
            'questions' => $application->questions->map(function (ApplicationQuestion $question) use ($user, $histories): array {
                $answer = $question->answer;

                return [
                    'ulid' => $question->ulid,
                    'prompt_text' => $question->prompt_text,
                    'origin' => $question->origin,
                    'word_limit' => $question->word_limit,
                    'char_limit' => $question->char_limit,
                    'answer' => $answer === null ? null : [
                        'body' => $answer->body(),
                        'status' => $answer->status,
                        'lock_version' => $answer->lock_version,
                        'version_no' => $answer->currentVersion?->version_no,
                        'from_library' => $answer->currentVersion?->libraryEntry?->question,
                        'library_entry' => $answer->currentVersion?->libraryEntry?->ulid,
                        'approved_at' => $answer->approved_at?->toIso8601String(),
                        'approved_by' => $answer->approver?->name,
                        'generation' => $answer->generation_status === null ? null : [
                            'status' => $answer->generation_status,
                            'error' => $answer->generation_error,
                            'requested_at' => $answer->generation_requested_at?->toIso8601String(),
                        ],
                        'ai' => $answer->currentVersion?->author_type !== 'ai' ? null : [
                            'evidence' => collect($answer->currentVersion->evidence_pack ?? [])
                                ->map(fn (array $item, string $id): array => ['id' => $id, 'status' => $item['status'], 'text' => $item['text']])
                                ->values()->all(),
                            'claims' => self::withReviews($answer->currentVersion->grounding ?? [], 'claim', $answer->currentVersion, $answer->currentVersion->claimsToCheck()),
                            'questions' => self::withReviews($answer->currentVersion->clarification_questions ?? [], 'question', $answer->currentVersion),
                            'instruction' => $answer->currentVersion->instruction,
                            'from_resume_version' => $answer->currentVersion->resumeVersion?->version_no,
                            'to_check' => count($answer->currentVersion->openClaims()),
                            'needs_review' => $answer->needsClaimReview(),
                        ],
                    ],
                    'history' => ($histories[$answer->id ?? 0] ?? collect())->map(fn (AnswerDraftVersion $version): array => [
                        'revision_no' => $version->version_no,
                        'text' => $version->body,
                        'change_reason' => match ($version->created_via) {
                            'library' => __('Inserted from the answer library'),
                            'ai' => $version->instruction === null ? __('Drafted with AI') : __('Drafted with AI: “:instruction”', ['instruction' => $version->instruction]),
                            default => null,
                        },
                        'author' => $version->author?->name,
                        'from_suggestion' => false,
                        'created_at' => $version->created_at->toIso8601String(),
                    ])->values()->all(),
                    'can' => [
                        'update' => $user->can('update', $question) && ! ($answer?->isApproved() ?? false),
                        'approve' => $user->can('approve', $question),
                        'reopen' => $user->can('reopen', $question),
                    ],
                ];
            })->values()->all(),
            'library' => $library->map(fn (AnswerLibraryEntry $entry): array => [
                'ulid' => $entry->ulid,
                'question' => $entry->question,
                'body' => $entry->body(),
                'category' => $entry->category->value,
                'status' => $entry->status->value,
            ])->values()->all(),
            'categories' => array_map(
                fn (AnswerCategory $category): array => ['value' => $category->value, 'label' => $category->label()],
                AnswerCategory::cases(),
            ),
            'hasCoverLetter' => $application->questions->contains(fn (ApplicationQuestion $question): bool => $question->isCoverLetter()),
            'canAdd' => $user->can('update', $application),
            'hasAnalysis' => $application->analyses()->where('status', 'succeeded')->exists(),
            'subjects' => ClaimSubject::options(),
            'maxQuestions' => BulkStoreQuestionsRequest::MAX_QUESTIONS,
        ]);
    }

    /**
     * AI claims or questions with their index, whether they need checking, and the decision made.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<int, mixed>  $toCheck  claims that need checking, keyed by index
     * @return list<array<string, mixed>>
     */
    private static function withReviews(array $items, string $itemType, AnswerDraftVersion $version, array $toCheck = []): array
    {
        $reviews = $version->reviews->where('item_type', $itemType)->keyBy('item_index');

        return array_map(function (int $index, array $item) use ($reviews, $toCheck): array {
            $review = $reviews->get($index);

            return [
                ...$item,
                'index' => $index,
                'to_check' => array_key_exists($index, $toCheck),
                'review' => $review === null ? null : [
                    'decision' => $review->decision,
                    'claim_status' => $review->claim?->status->value,
                ],
            ];
        }, array_keys($items), $items);
    }

    public function store(SaveQuestionRequest $request, JobApplication $application): RedirectResponse
    {
        Gate::authorize('update', $application);

        $this->questions->add($application, $request->string('prompt_text')->toString(), $request->wordLimit(), $request->charLimit(), $request->user());

        return back();
    }

    public function bulkStore(BulkStoreQuestionsRequest $request, JobApplication $application): RedirectResponse
    {
        Gate::authorize('update', $application);

        $added = $this->questions->addMany($application, $request->parsedQuestions(), $request->user());
        $count = count($added);

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('{1} Added 1 question.|[0,*] Added :count questions.', $count, ['count' => $count])]);

        return back();
    }

    public function coverLetter(Request $request, JobApplication $application): RedirectResponse
    {
        Gate::authorize('update', $application);

        $question = $this->questions->coverLetter($application, $request->user());

        return to_route('applications.questions.index', [$application, '#'.$question->ulid]);
    }

    public function update(SaveQuestionRequest $request, JobApplication $application, ApplicationQuestion $question): RedirectResponse
    {
        Gate::authorize('update', $question);

        $this->questions->update($question, $request->string('prompt_text')->toString(), $request->wordLimit(), $request->charLimit(), $request->user());

        return back();
    }

    public function destroy(Request $request, JobApplication $application, ApplicationQuestion $question): RedirectResponse
    {
        Gate::authorize('update', $question);

        $this->questions->delete($question, $request->user());

        return back();
    }

    public function move(Request $request, JobApplication $application, ApplicationQuestion $question): RedirectResponse
    {
        Gate::authorize('update', $question);

        $validated = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]]);

        $this->questions->move($question, $validated['direction'] === 'up' ? -1 : 1);

        return back();
    }

    public function saveAnswer(SaveAnswerDraftRequest $request, JobApplication $application, ApplicationQuestion $question): RedirectResponse
    {
        Gate::authorize('update', $question);

        $this->questions->saveAnswer($question, $request->body(), $request->libraryEntry(), $request->integer('lock_version'), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Answer saved.')]);

        return back();
    }

    /**
     * Queue an AI-written draft (M8). It arrives as a new version of the answer.
     */
    public function draft(Request $request, JobApplication $application, ApplicationQuestion $question): RedirectResponse
    {
        Gate::authorize('update', $question);

        $validated = $request->validate(['instruction' => ['nullable', 'string', 'max:500']]);
        $instruction = PlainText::normalize((string) ($validated['instruction'] ?? ''));

        $this->aiAnswers->request($question, $request->user(), $instruction === '' ? null : $instruction);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Writing a draft. It appears here when ready.')]);

        return back();
    }

    /**
     * The candidate has checked the AI draft's unconfirmed claims and keeps them as worded.
     */
    public function claimsReviewed(Request $request, JobApplication $application, ApplicationQuestion $question): RedirectResponse
    {
        Gate::authorize('approve', $question);

        $this->aiAnswers->markClaimsReviewed($question, $request->user());

        return back();
    }

    public function approve(Request $request, JobApplication $application, ApplicationQuestion $question): RedirectResponse
    {
        Gate::authorize('approve', $question);

        $this->questions->approveAnswer($question, $request->user());

        return back();
    }

    public function reopen(Request $request, JobApplication $application, ApplicationQuestion $question): RedirectResponse
    {
        Gate::authorize('reopen', $question);

        $this->questions->reopenAnswer($question, $request->user());

        return back();
    }

    /**
     * Keep the answer for future applications.
     */
    public function saveToLibrary(Request $request, JobApplication $application, ApplicationQuestion $question): RedirectResponse
    {
        Gate::authorize('update', $question);

        $validated = $request->validate(['category' => ['required', Rule::enum(AnswerCategory::class)]]);

        $entry = $this->questions->saveToLibrary($question, AnswerCategory::from($validated['category']), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => $entry->status === AnswerStatus::Approved
            ? __('Saved to your answer library.')
            : __('Saved to the answer library as a draft. The candidate will approve it.')]);

        return back();
    }
}
