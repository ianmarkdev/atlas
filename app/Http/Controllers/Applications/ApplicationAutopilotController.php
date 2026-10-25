<?php

namespace App\Http\Controllers\Applications;

use App\Domain\Applications\ApplicationAutopilot;
use App\Domain\Applications\JobApplication;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * "Prepare in background" for the applications chosen on the list: the scheduler then analyses
 * each, adds its questions, tailors the resume and drafts the answers and cover letter
 * (ApplicationAutopilot), for review later.
 */
class ApplicationAutopilotController extends Controller
{
    public function __construct(private ApplicationAutopilot $autopilot) {}

    public function store(Request $request): RedirectResponse
    {
        $applications = $this->chosen($request);
        $applications->each(fn (JobApplication $application) => $this->autopilot->start($application, $request->user()));

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice(
            '{1} Preparing 1 application in the background. It shows “Ready for review” when done.|[2,*] Preparing :count applications in the background. Each shows “Ready for review” when done.',
            $applications->count(),
        )]);

        return back();
    }

    public function destroy(Request $request): RedirectResponse
    {
        $applications = $this->chosen($request);
        $applications->each(fn (JobApplication $application) => $this->autopilot->stop($application, $request->user()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Stopped. AI work already queued still finishes as drafts.')]);

        return back();
    }

    /**
     * @return Collection<int, JobApplication>
     */
    private function chosen(Request $request): Collection
    {
        $validated = $request->validate([
            'applications' => ['required', 'array', 'min:1', 'max:100'],
            'applications.*' => ['required', 'string', 'distinct'],
        ], [
            'applications.required' => __('Choose at least one application.'),
        ]);

        $applications = JobApplication::query()->whereIn('ulid', $validated['applications'])->get();

        abort_if($applications->count() !== count($validated['applications']), 404);
        $applications->each(fn (JobApplication $application) => Gate::authorize('update', $application));

        return $applications;
    }
}
