<?php

namespace App\Http\Controllers\Applications;

use App\Domain\Applications\ClarificationRequest;
use App\Domain\Applications\ClarificationService;
use App\Domain\Applications\JobAnalysisService;
use App\Domain\Applications\JobApplication;
use App\Domain\Knowledge\ClaimSubject;
use App\Domain\Shared\PlainText;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Answer or dismiss the questions an analysis raised about gaps (M7).
 */
class ClarificationController extends Controller
{
    public function __construct(private ClarificationService $clarifications, private JobAnalysisService $analyses) {}

    public function answer(Request $request, JobApplication $application, ClarificationRequest $clarification): RedirectResponse
    {
        Gate::authorize('update', $application);

        $validated = $request->validate([
            'answer' => ['required', 'string', 'max:1000'],
            'subject' => ['nullable', 'string'],
        ]);

        $answer = PlainText::normalize($validated['answer']);
        $subject = ClaimSubject::resolve($validated['subject'] ?? null);

        if ($answer === '') {
            throw ValidationException::withMessages(['answer' => __('Write what you did.')]);
        }

        if ($subject === false) {
            throw ValidationException::withMessages(['subject' => __('Choose one of your experiences or projects.')]);
        }

        $this->clarifications->answer($clarification, $answer, $subject, $request->user());

        // The new bullet point may close this gap (and others): match again.
        $latest = $application->latestAnalysis()->first();

        if ($latest !== null) {
            $this->analyses->refreshMatches($latest->setRelation('application', $application));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $request->user()->isCandidate()
            ? __('Added to your library and matched again.')
            : __('Added to the library as a proposed bullet point for the candidate to verify.')]);

        return back();
    }

    public function dismiss(Request $request, JobApplication $application, ClarificationRequest $clarification): RedirectResponse
    {
        Gate::authorize('update', $application);

        $this->clarifications->dismiss($clarification, $request->user());

        return back();
    }
}
