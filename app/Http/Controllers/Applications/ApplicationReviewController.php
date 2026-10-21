<?php

namespace App\Http\Controllers\Applications;

use App\Domain\Applications\ApplicationReviewService;
use App\Domain\Applications\JobApplication;
use App\Domain\Shared\PlainText;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * The VA workflow of one application (M7a slice D): assignment and the review steps.
 */
class ApplicationReviewController extends Controller
{
    public function __construct(private ApplicationReviewService $reviews) {}

    public function assign(Request $request, JobApplication $application): RedirectResponse
    {
        $validated = $request->validate(['assignee' => ['nullable', 'string']]);
        $assignee = null;

        if (($validated['assignee'] ?? null) !== null) {
            $assignee = User::query()
                ->where('ulid', $validated['assignee'])
                ->whereHas('membership', fn ($query) => $query->where('workspace_id', $application->workspace_id))
                ->first();

            if ($assignee === null || ! ApplicationReviewService::canBeAssigned($assignee)) {
                throw ValidationException::withMessages(['assignee' => __('Choose an active member of your team.')]);
            }
        }

        Gate::authorize('assign', [$application, $assignee]);

        $this->reviews->assign($application, $assignee, $request->user());

        return back();
    }

    public function start(Request $request, JobApplication $application): RedirectResponse
    {
        Gate::authorize('update', $application);

        $this->reviews->start($application, $request->user());

        return back();
    }

    public function submit(Request $request, JobApplication $application): RedirectResponse
    {
        Gate::authorize('submitForReview', $application);

        $this->reviews->submit($application, $this->note($request, required: false), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Sent to the candidate for review.')]);

        return back();
    }

    public function requestChanges(Request $request, JobApplication $application): RedirectResponse
    {
        Gate::authorize('review', $application);

        $this->reviews->requestChanges($application, (string) $this->note($request, required: true), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Changes requested.')]);

        return back();
    }

    public function approve(Request $request, JobApplication $application): RedirectResponse
    {
        Gate::authorize('review', $application);

        $this->reviews->approve($application, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Application approved. Send it, then move it to Applied.')]);

        return back();
    }

    public function reopen(Request $request, JobApplication $application): RedirectResponse
    {
        Gate::authorize('review', $application);

        $this->reviews->reopen($application, $request->user());

        return back();
    }

    private function note(Request $request, bool $required): ?string
    {
        $validated = $request->validate([
            'note' => [$required ? 'required' : 'nullable', 'string', 'max:2000'],
        ]);

        $note = PlainText::normalize((string) ($validated['note'] ?? ''), multiline: true);

        if ($required && $note === '') {
            throw ValidationException::withMessages(['note' => __('Say what should change.')]);
        }

        return $note === '' ? null : $note;
    }
}
