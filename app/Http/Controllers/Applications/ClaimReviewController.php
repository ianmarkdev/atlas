<?php

namespace App\Http\Controllers\Applications;

use App\Domain\Applications\ApplicationQuestion;
use App\Domain\Applications\ClaimReviewService;
use App\Domain\Applications\JobApplication;
use App\Domain\Knowledge\ClaimSubject;
use App\Domain\Shared\PlainText;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * One-by-one review of an AI draft's unconfirmed claims and questions (M9).
 */
class ClaimReviewController extends Controller
{
    public function __construct(private ClaimReviewService $reviews) {}

    /**
     * Keep a claim as worded: the candidate vouches for it, so it no longer blocks approval.
     */
    public function accept(Request $request, JobApplication $application, ApplicationQuestion $question): RedirectResponse
    {
        Gate::authorize('approve', $question);

        $validated = $request->validate(['index' => ['required', 'integer', 'min:0']]);

        $this->reviews->accept($question->load('answer.currentVersion'), (int) $validated['index'], $request->user());

        return back();
    }

    /**
     * Save a claim, or the answer to an AI question, as a bullet point in the library.
     */
    public function addToLibrary(Request $request, JobApplication $application, ApplicationQuestion $question): RedirectResponse
    {
        Gate::authorize('update', $question);

        $validated = $request->validate([
            'item_type' => ['required', Rule::in(['claim', 'question'])],
            'index' => ['required', 'integer', 'min:0'],
            'statement' => ['required', 'string', 'max:1000'],
            'subject' => ['nullable', 'string'],
        ]);

        $statement = PlainText::normalize($validated['statement']);
        $subject = ClaimSubject::resolve($validated['subject'] ?? null);

        if ($statement === '' || preg_match('/\[confirm:[^\]]*\]/iu', $statement) === 1) {
            throw ValidationException::withMessages(['statement' => __('Write the fact as it is, with the real details instead of [confirm: …].')]);
        }

        if ($subject === false) {
            throw ValidationException::withMessages(['subject' => __('Choose one of your experiences or projects.')]);
        }

        $this->reviews->addToLibrary($question->load('answer.currentVersion'), $validated['item_type'], (int) $validated['index'], $statement, $subject, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => $request->user()->isCandidate()
            ? __('Added to your library as a verified bullet point.')
            : __('Added to the library as a proposed bullet point for the candidate to verify.')]);

        return back();
    }

    /**
     * Skip an AI question (nothing to add).
     */
    public function dismiss(Request $request, JobApplication $application, ApplicationQuestion $question): RedirectResponse
    {
        Gate::authorize('update', $question);

        $validated = $request->validate(['index' => ['required', 'integer', 'min:0']]);

        $this->reviews->dismissQuestion($question->load('answer.currentVersion'), (int) $validated['index'], $request->user());

        return back();
    }
}
