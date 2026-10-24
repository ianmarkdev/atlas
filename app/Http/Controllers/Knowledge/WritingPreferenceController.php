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
            'drafts' => $preference->drafts->value,
            'drafts_label' => $preference->drafts === WritingPreferenceDrafts::All ? null : $preference->drafts->label(),
            'learned' => str_starts_with($preference->created_via, 'proposal:'),
            'seen_in' => $preference->supporting_edits_count,
            // Drafts written with this rule that still needed it applied by hand.
            'missed_in' => $preference->missed_in_count,
            'can' => ['archive' => $user->can('archive', $preference), 'update' => $user->can('update', $preference)],
        ];
        $active = WritingPreference::query()->appliesTo($user)->withCount(['supportingEdits', 'missedIn'])->oldest()->get();

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

    /**
     * Add a rule (or suggest it, for a VA's rule for everyone). A rule sharing words with rules in
     * place is not added at once: the page shows those rules (flash `similarRules`) and the user
     * adds it anyway (`confirmed`) or has it replace one of them (`replaces`, its ulid).
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'rule' => ['required', 'string', 'max:200'],
            'kind' => ['required', Rule::enum(WritingPreferenceKind::class)],
            'drafts' => ['nullable', Rule::enum(WritingPreferenceDrafts::class)],
            'personal' => ['boolean'],
            'confirmed' => ['boolean'],
            'replaces' => ['nullable', 'string', 'max:26'],
        ]);
        $rule = PlainText::normalize($validated['rule']);
        $kind = WritingPreferenceKind::from($validated['kind']);
        $drafts = WritingPreferenceDrafts::tryFrom((string) ($validated['drafts'] ?? '')) ?? WritingPreferenceDrafts::All;
        $user = $request->user();
        $isPersonal = (bool) ($validated['personal'] ?? false);
        $owner = $isPersonal ? $user : null;

        if ($rule === '') {
            throw ValidationException::withMessages(['rule' => __('Write the rule.')]);
        }

        if ($this->preferences->exists($rule, $owner)) {
            throw ValidationException::withMessages(['rule' => __('This rule is already in the list.')]);
        }

        $replaced = null;

        if (isset($validated['replaces'])) {
            $replaced = WritingPreference::query()->appliesTo($owner)->where('ulid', $validated['replaces'])->first();

            // A VA's rule for everyone is only a suggestion: naming a rule to replace is a hint for the candidate.
            if ($replaced === null || (($isPersonal || $user->isCandidate()) && ! $user->can('archive', $replaced))) {
                throw ValidationException::withMessages(['replaces' => __('You cannot replace that rule.')]);
            }
        } elseif (! ($validated['confirmed'] ?? false)) {
            $similar = WritingPreference::similarTo($rule, $owner);

            if ($similar !== []) {
                Inertia::flash('similarRules', [
                    'rule' => $rule,
                    'kind' => $kind->value,
                    'drafts' => $drafts->value,
                    'personal' => $isPersonal,
                    'similar' => array_map(fn (array $match): array => [
                        'ulid' => $match['rule']->ulid,
                        'rule' => $match['rule']->rule,
                        'kind_label' => $match['rule']->kind->label(),
                        'can_replace' => $user->can('archive', $match['rule']) || (! $isPersonal && ! $user->isCandidate()),
                    ], $similar),
                ]);

                return back();
            }
        }

        if ($isPersonal || $user->isCandidate()) {
            $this->preferences->create($rule, $kind, $owner, $user, drafts: $drafts, replacing: $replaced);

            Inertia::flash('toast', ['type' => 'success', 'message' => $replaced === null ? __('Rule added. New drafts follow it.') : __('Rule replaced. New drafts follow the new one.')]);

            return back();
        }

        $this->proposals->suggest('create_writing_preference', null, [
            'rule' => $rule,
            'kind' => $kind->value,
            'scope' => 'profile',
            'drafts' => $drafts->value,
            ...($replaced === null ? [] : ['replaces' => $replaced->id]),
        ], null, ['origin' => 'manual', 'proposer' => $user]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Rule suggested. It applies to every draft once the candidate approves it.')]);

        return back();
    }

    public function drafts(Request $request, WritingPreference $preference): RedirectResponse
    {
        Gate::authorize('update', $preference);

        $validated = $request->validate(['drafts' => ['required', Rule::enum(WritingPreferenceDrafts::class)]]);

        $this->preferences->setDrafts($preference, WritingPreferenceDrafts::from($validated['drafts']), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Rule updated. New drafts follow it.')]);

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
