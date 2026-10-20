<?php

namespace App\Concerns;

use App\Domain\Knowledge\AnswerLibraryEntry;
use App\Domain\Knowledge\Claim;
use App\Domain\Knowledge\Credential;
use App\Domain\Knowledge\Employment;
use App\Domain\Knowledge\Project;
use App\Domain\Knowledge\ProposalService;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * For library controllers: the candidate (or a VA on a proposed item) edits directly; a VA
 * editing a verified item sends a suggestion to the candidate instead (D5).
 */
trait ProposesKnowledgeChanges
{
    /**
     * @return bool true when this user's save becomes a suggestion rather than an edit
     */
    protected function proposing(User $user, Claim|Employment|Project|Credential|AnswerLibraryEntry $record): bool
    {
        if ($user->can('update', $record)) {
            return false;
        }

        abort_unless($user->can('propose', $record), Response::HTTP_FORBIDDEN);

        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function proposeChange(Claim|Employment|Project|Credential|AnswerLibraryEntry $record, array $payload, Request $request): RedirectResponse
    {
        $rationale = $request->input('rationale');

        app(ProposalService::class)->propose(
            $record,
            $payload,
            is_string($rationale) ? $rationale : null,
            $request->integer('lock_version'),
            $request->user(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Change suggested. The candidate will review it.')]);

        return to_route('knowledge.index');
    }
}
