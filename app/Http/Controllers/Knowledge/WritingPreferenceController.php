<?php

namespace App\Http\Controllers\Knowledge;

use App\Domain\Knowledge\KnowledgeProposal;
use App\Domain\Knowledge\ProposalService;
use App\Domain\Knowledge\ProposalStatus;
use App\Domain\Knowledge\WritingPreference;
use App\Domain\Knowledge\WritingPreferenceDrafts;
use App\Domain\Knowledge\WritingPreferenceKind;
use App\Domain\Knowledge\WritingPreferenceService;
use App\Domain\Shared\PlainText;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Writing preferences (M10): rules every AI draft follows, for everyone writing for the
 * candidate or just for the current user. A VA's rule for everyone is suggested to the candidate.
 */
class WritingPreferenceController extends Controller
{
    public function __construct(private WritingPreferenceService $preferences, private ProposalService $proposals) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $present = fn (WritingPreference $preference): array => [
            'ulid' => $preference->ulid,
            'rule' => $preference->rule,
            'kind' => $preference->kind->value,
            'kind_label' => $preference->kind->label(),
            'drafts_label' => $preference->drafts === WritingPreferenceDrafts::All ? null : $preference->drafts->label(),
            'learned' => str_starts_with($preference->created_via, 'proposal:'),
            'seen_in' => $preference->supporting_edits_count,
            'can' => ['archive' => $user->can('archive', $preference)],
        ];
        $active = WritingPreference::query()->appliesTo($user)->withCount('supportingEdits')->oldest()->get();

        return Inertia::render('knowledge/writing-style', [
            'shared' => $active->where('scope', 'profile')->map($present)->values()->all(),
            'personal' => $active->where('scope', 'user')->map($present)->values()->all(),
            'suggested' => KnowledgeProposal::query()
                ->where('proposal_type', 'create_writing_preference')
                ->where('status', ProposalStatus::Pending)
                ->where(fn ($query) => $user->isCandidate()
                    ? $query->whereNull('reviewer_user_id')->orWhere('reviewer_user_id', $user->id)
                    : $query->where('reviewer_user_id', $user->id)->orWhere('proposed_by_user_id', $user->id))
                ->count(),
            'kinds' => WritingPreferenceKind::options(),
            'draftTypes' => WritingPreferenceDrafts::options(),
            'isCandidate' => $user->isCandidate(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'rule' => ['required', 'string', 'max:200'],
            'kind' => ['required', Rule::enum(WritingPreferenceKind::class)],
            'drafts' => ['nullable', Rule::enum(WritingPreferenceDrafts::class)],
            'personal' => ['boolean'],
        ]);
        $rule = PlainText::normalize($validated['rule']);
        $kind = WritingPreferenceKind::from($validated['kind']);
        $drafts = WritingPreferenceDrafts::tryFrom((string) ($validated['drafts'] ?? '')) ?? WritingPreferenceDrafts::All;
        $user = $request->user();
        $isPersonal = (bool) ($validated['personal'] ?? false);

        if ($rule === '') {
            throw ValidationException::withMessages(['rule' => __('Write the rule.')]);
        }

        if ($isPersonal || $user->isCandidate()) {
            $this->preferences->create($rule, $kind, $isPersonal ? $user : null, $user, drafts: $drafts);

            Inertia::flash('toast', ['type' => 'success', 'message' => __('Rule added. New drafts follow it.')]);

            return back();
        }

        if ($this->preferences->exists($rule, null)) {
            throw ValidationException::withMessages(['rule' => __('This rule is already in the list.')]);
        }

        $this->proposals->suggest('create_writing_preference', null, ['rule' => $rule, 'kind' => $kind->value, 'scope' => 'profile', 'drafts' => $drafts->value], null, ['origin' => 'manual', 'proposer' => $user]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Rule suggested. It applies to every draft once the candidate approves it.')]);

        return back();
    }

    public function archive(Request $request, WritingPreference $preference): RedirectResponse
    {
        Gate::authorize('archive', $preference);

        $this->preferences->archive($preference, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Rule removed.')]);

        return back();
    }
}
