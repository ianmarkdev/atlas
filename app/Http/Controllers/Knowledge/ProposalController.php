<?php

namespace App\Http\Controllers\Knowledge;

use App\Domain\Knowledge\ClaimSubject;
use App\Domain\Knowledge\KnowledgeProposal;
use App\Domain\Knowledge\ProposalService;
use App\Domain\Knowledge\ProposalStatus;
use App\Domain\Knowledge\WritingPreferenceDrafts;
use App\Domain\Shared\PlainText;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\KnowledgeProposalResource;
use App\Jobs\SuggestAchievements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The approval queue: suggested changes to verified facts, and what the AI learned from edited
 * answers (M10). The candidate sees every suggestion and decides; a VA sees the suggestions they
 * made, and decides the personal writing preferences learned from their own edits.
 */
class ProposalController extends Controller
{
    public function __construct(private ProposalService $proposals) {}

    public function index(Request $request, TenantContext $tenant): Response
    {
        $user = $request->user();
        $isReviewer = $user->can('approve-proposals');
        $relations = ['target', 'proposer:id,name', 'decider:id,name', 'feedbackEvent.answerDraft.question.application'];

        // A VA sees what they suggested, and the personal writing preferences learned from their
        // edits; those are not the candidate's to see.
        $visible = fn ($query) => $query->where(fn ($mine) => $isReviewer
            ? $mine->whereNull('reviewer_user_id')->orWhere('reviewer_user_id', $user->id)
            : $mine->where('proposed_by_user_id', $user->id)->orWhere('reviewer_user_id', $user->id));

        $pending = KnowledgeProposal::query()
            ->where('status', ProposalStatus::Pending)
            ->tap($visible)
            ->with($relations)
            ->oldest()
            ->get();

        $decided = KnowledgeProposal::query()
            ->where('status', '!=', ProposalStatus::Pending)
            ->tap($visible)
            ->with($relations)
            ->latest('updated_at')
            ->limit(20)
            ->get();

        $loadClaimRevisions = function ($proposals): void {
            foreach ($proposals as $proposal) {
                if (in_array($proposal->target_type, ['claim', 'answer_library_entry', 'resume_variant'], true)) {
                    $proposal->target?->loadMissing('currentRevision');
                }
            }
        };
        $loadClaimRevisions($pending);
        $loadClaimRevisions($decided);

        return Inertia::render('knowledge/proposals', [
            'pending' => KnowledgeProposalResource::collection($pending)->resolve(),
            'decided' => KnowledgeProposalResource::collection($decided)->resolve(),
            'isReviewer' => $isReviewer,
            'subjects' => $isReviewer ? ClaimSubject::options() : [],
            'draftTypes' => WritingPreferenceDrafts::options(),
            'suggesting' => $isReviewer ? Cache::get(SuggestAchievements::statusKey($tenant->workspaceId())) : null,
        ]);
    }

    /**
     * Ask the AI which bullet points would be stronger with a result (M9). Route middleware
     * `can:approve-proposals`.
     */
    public function suggestAchievements(Request $request, TenantContext $tenant): RedirectResponse
    {
        $workspaceId = $tenant->workspaceId();
        $key = SuggestAchievements::statusKey($workspaceId);

        if ((Cache::get($key)['status'] ?? null) === 'queued') {
            throw ValidationException::withMessages(['suggest' => __('Already looking for achievements.')]);
        }

        Cache::put($key, ['status' => 'queued', 'error' => null], now()->addHour());
        SuggestAchievements::dispatch($workspaceId, $request->user()->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Looking for bullet points that could show a result. Suggestions appear here.')]);

        return back();
    }

    /**
     * Approve as suggested, or with the reviewer's own wording (edit-then-approve). Route
     * middleware `can:decide,proposal`.
     */
    public function approve(Request $request, KnowledgeProposal $proposal): RedirectResponse
    {
        $validated = $request->validate([
            'statement' => ['nullable', 'string', 'max:1000'],
            'body' => ['nullable', 'string', 'max:10000'],
            'rule' => ['nullable', 'string', 'max:200'],
            'subject' => ['nullable', 'string', 'max:100'],
            'drafts' => ['nullable', Rule::enum(WritingPreferenceDrafts::class)],
        ]);

        $edits = [];

        foreach (['statement' => false, 'body' => true, 'rule' => false] as $field => $multiline) {
            $value = PlainText::normalize((string) ($validated[$field] ?? ''), multiline: $multiline);

            if ($value !== '') {
                $edits[$field] = $value;
            }
        }

        if ($proposal->proposal_type === 'create_claim' && isset($validated['subject'])) {
            $subject = ClaimSubject::resolve($validated['subject']);

            if ($subject === false) {
                throw ValidationException::withMessages(['subject' => __('Choose a job or project from your library.')]);
            }

            $edits['subject'] = $subject;
        }

        if ($proposal->proposal_type === 'create_writing_preference' && isset($validated['drafts'])) {
            $edits['drafts'] = WritingPreferenceDrafts::from($validated['drafts']);
        }

        $applied = $this->proposals->approve($proposal, $request->user(), $edits);

        Inertia::flash('toast', $applied
            ? ['type' => 'success', 'message' => __('Change approved and applied.')]
            : ['type' => 'error', 'message' => __('The item changed after this was suggested, so it was not applied. Ask for the change again if it is still needed.')]);

        return back();
    }

    /**
     * Route middleware `can:decide,proposal`.
     */
    public function reject(Request $request, KnowledgeProposal $proposal): RedirectResponse
    {
        $validated = $request->validate(['decision_note' => ['nullable', 'string', 'max:1000']]);
        $note = isset($validated['decision_note']) ? PlainText::normalize($validated['decision_note'], multiline: true) : '';

        $this->proposals->reject($proposal, $note === '' ? null : $note, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Suggestion rejected.')]);

        return back();
    }
}
