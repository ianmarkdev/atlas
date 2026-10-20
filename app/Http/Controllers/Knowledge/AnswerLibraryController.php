<?php

namespace App\Http\Controllers\Knowledge;

use App\Concerns\ProposesKnowledgeChanges;
use App\Domain\Knowledge\AnswerCategory;
use App\Domain\Knowledge\AnswerLibraryEntry;
use App\Domain\Knowledge\AnswerLibraryService;
use App\Domain\Knowledge\AnswerStatus;
use App\Domain\Knowledge\Claim;
use App\Domain\Knowledge\ClaimStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Knowledge\BulkStoreAnswersRequest;
use App\Http\Requests\Knowledge\SaveAnswerRequest;
use App\Http\Resources\AnswerResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The answer library: standard application questions with the candidate's answers, ready to
 * copy into application forms (and, later, reused by generation as examples and evidence).
 */
class AnswerLibraryController extends Controller
{
    use ProposesKnowledgeChanges;

    public function __construct(private AnswerLibraryService $answers) {}

    /**
     * Searchable by question and answer text, filterable by category; archived answers on request.
     */
    public function index(Request $request): Response
    {
        $search = trim($request->string('q')->toString());
        $category = $request->enum('category', AnswerCategory::class);
        $showArchived = $request->boolean('archived');

        $entries = AnswerLibraryEntry::query()
            ->with('currentRevision')
            ->whereIn('status', $showArchived ? [AnswerStatus::Archived] : [AnswerStatus::Approved, AnswerStatus::Draft])
            ->when($category !== null, fn (Builder $query) => $query->where('category', $category))
            ->when($search !== '', function (Builder $query) use ($search): void {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $query->where(fn (Builder $inner) => $inner
                    ->where('question', 'ilike', $like)
                    ->orWhereHas('currentRevision', fn (Builder $revision) => $revision->where('body', 'ilike', $like)));
            })
            ->orderBy('category')
            ->orderBy('question')
            ->get();

        return Inertia::render('knowledge/answers', [
            'answers' => AnswerResource::collection($entries)->resolve(),
            'filters' => ['q' => $search, 'category' => $category?->value, 'archived' => $showArchived],
            'categories' => $this->categories(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('knowledge/answer-form', [
            'answer' => null,
            'proposing' => false,
            ...$this->formOptions(),
        ]);
    }

    public function store(SaveAnswerRequest $request): RedirectResponse
    {
        $entry = $this->answers->create(
            $request->string('question')->toString(),
            $request->string('body')->toString(),
            $request->enum('category', AnswerCategory::class) ?? AnswerCategory::Other,
            $request->claimUlids(),
            $request->user(),
        );

        $this->flashSaved($entry);

        return to_route('knowledge.answers.index');
    }

    public function edit(Request $request, AnswerLibraryEntry $answer): Response
    {
        return Inertia::render('knowledge/answer-form', [
            'answer' => AnswerResource::make($answer->load('currentRevision'))->resolve(),
            'proposing' => $this->proposing($request->user(), $answer),
            ...$this->formOptions(),
        ]);
    }

    public function update(SaveAnswerRequest $request, AnswerLibraryEntry $answer): RedirectResponse
    {
        if ($this->proposing($request->user(), $answer)) {
            $this->proposeChange($answer, $request->proposalPayload(), $request);

            return to_route('knowledge.answers.index');
        }

        $this->answers->revise(
            $answer,
            $request->string('question')->toString(),
            $request->string('body')->toString(),
            $request->enum('category', AnswerCategory::class) ?? $answer->category,
            $request->claimUlids(),
            $request->input('change_reason'),
            $request->integer('lock_version'),
            $request->user(),
        );

        $this->flashSaved($answer);

        return to_route('knowledge.answers.index');
    }

    public function approve(Request $request, AnswerLibraryEntry $answer): RedirectResponse
    {
        Gate::authorize('approve', $answer);

        $this->answers->approve($answer, $request->user());

        return back();
    }

    public function archive(Request $request, AnswerLibraryEntry $answer): RedirectResponse
    {
        Gate::authorize('archive', $answer);

        $this->answers->archive($answer, $request->user());

        return back();
    }

    public function restore(Request $request, AnswerLibraryEntry $answer): RedirectResponse
    {
        Gate::authorize('restore', $answer);

        $this->answers->restore($answer, $request->user());

        return back();
    }

    public function bulkCreate(): Response
    {
        return Inertia::render('knowledge/answers-bulk', [
            'categories' => $this->categories(),
            'maxPairs' => BulkStoreAnswersRequest::MAX_PAIRS,
        ]);
    }

    public function bulkStore(BulkStoreAnswersRequest $request): RedirectResponse
    {
        $entries = $this->answers->createMany(
            $request->parsedPairs(),
            $request->enum('category', AnswerCategory::class) ?? AnswerCategory::Other,
            $request->user(),
        );

        $count = count($entries);
        $message = trans_choice('{1} Added 1 answer.|[0,*] Added :count answers.', $count, ['count' => $count]);

        if (! $request->user()->isCandidate()) {
            $message .= ' '.__('They are drafts until the candidate approves them.');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('knowledge.answers.index');
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function categories(): array
    {
        return array_map(
            fn (AnswerCategory $category): array => ['value' => $category->value, 'label' => $category->label()],
            AnswerCategory::cases(),
        );
    }

    /**
     * Categories plus the bullet points an answer can cite, grouped by what they are about.
     *
     * @return array{categories: list<array{value: string, label: string}>, evidence: list<array{label: string, claims: list<array{ulid: string, statement: string}>}>}
     */
    private function formOptions(): array
    {
        $groups = [];

        $claims = Claim::query()
            ->whereIn('status', ClaimStatus::live())
            ->with(['currentRevision', 'subject'])
            ->oldest('id')
            ->get();

        foreach ($claims as $claim) {
            $subject = $claim->subject;
            $label = match (true) {
                $subject === null => __('Other facts'),
                $subject->getMorphClass() === 'employment' => "{$subject->getAttribute('title')} — {$subject->getAttribute('employer_name')}",
                default => (string) $subject->getAttribute('name'),
            };

            $groups[$label][] = ['ulid' => $claim->ulid, 'statement' => $claim->statement()];
        }

        $evidence = [];

        foreach ($groups as $label => $items) {
            $evidence[] = ['label' => (string) $label, 'claims' => $items];
        }

        return ['categories' => $this->categories(), 'evidence' => $evidence];
    }

    private function flashSaved(AnswerLibraryEntry $entry): void
    {
        $message = $entry->status === AnswerStatus::Approved
            ? __('Answer saved.')
            : __('Answer saved as a draft. The candidate will approve it.');

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);
    }
}
