<?php

namespace App\Http\Controllers\Applications;

use App\Domain\Applications\ClarificationRequest;
use App\Domain\Applications\JobAnalysis;
use App\Domain\Applications\JobAnalysisService;
use App\Domain\Applications\JobApplication;
use App\Domain\Knowledge\ClaimSubject;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The AI brief of an application (M7): request an analysis, read the brief with its
 * requirement ↔ evidence matrix, refresh the evidence, and add the questions it found.
 */
class JobAnalysisController extends Controller
{
    public function __construct(private JobAnalysisService $analyses) {}

    /**
     * The newest analysis (or the one asked for), with the list of earlier ones.
     */
    public function show(Request $request, JobApplication $application): Response
    {
        $history = $application->analyses()->with('requester:id,name', 'generationRun:id,provider,model,latency_ms')->limit(20)->get();
        $selected = $request->filled('analysis')
            ? $history->firstWhere('ulid', $request->string('analysis')->toString())
            : $history->first();

        $existingQuestions = array_map(JobAnalysisService::normaliseQuestion(...), $application->questions()->pluck('prompt_text')->all());

        return Inertia::render('applications/analysis', [
            'application' => [
                'ulid' => $application->ulid,
                'company_name' => $application->company_name,
                'role_title' => $application->role_title,
                'has_job_description' => $application->posting()->exists(),
            ],
            'analysis' => $selected === null ? null : [
                'ulid' => $selected->ulid,
                'status' => $selected->status,
                'brief' => $selected->brief,
                'matches' => $selected->matches,
                'matched_at' => $selected->matched_at?->toIso8601String(),
                'refreshing' => $selected->rematch_requested_at !== null,
                'error_message' => $selected->error_message,
                'created_at' => $selected->created_at?->toIso8601String(),
                'completed_at' => $selected->completed_at?->toIso8601String(),
                'requested_by' => $selected->requester?->name,
                'model' => $selected->generationRun === null ? null : "{$selected->generationRun->provider}/{$selected->generationRun->model}",
                'latency_ms' => $selected->generationRun?->latency_ms,
                'is_latest_posting' => $selected->job_posting_id === $application->posting?->id,
                'question_already_added' => array_map(
                    fn (mixed $question): bool => in_array(JobAnalysisService::normaliseQuestion((string) $question), $existingQuestions, true),
                    array_values((array) ($selected->brief['questions'] ?? [])),
                ),
            ],
            'history' => $history->map(fn (JobAnalysis $analysis): array => [
                'ulid' => $analysis->ulid,
                'status' => $analysis->status,
                'created_at' => $analysis->created_at?->toIso8601String(),
            ])->values()->all(),
            'clarifications' => $application->clarifications()->whereIn('status', ['open', 'answered'])->with('answerer:id,name')->get()
                ->map(fn (ClarificationRequest $clarification): array => [
                    'ulid' => $clarification->ulid,
                    'requirement' => $clarification->requirement,
                    'question' => $clarification->question_text,
                    'status' => $clarification->status,
                    'answer' => $clarification->answer_text,
                    'answered_by' => $clarification->answerer?->name,
                ])->values()->all(),
            'subjects' => ClaimSubject::options(),
            'canUpdate' => $request->user()->can('update', $application),
        ]);
    }

    public function store(Request $request, JobApplication $application): RedirectResponse
    {
        Gate::authorize('update', $application);

        $this->analyses->request($application, $request->user());

        return to_route('applications.analysis.show', $application);
    }

    public function refresh(Request $request, JobApplication $application, JobAnalysis $analysis): RedirectResponse
    {
        Gate::authorize('update', $application);

        $refreshing = $this->analyses->requestRefresh($analysis);

        Inertia::flash('toast', $refreshing
            ? ['type' => 'success', 'message' => __('Matching again against your current library and resumes. It updates here when done.')]
            : ['type' => 'error', 'message' => __('Only a finished analysis can be matched again.')]);

        return back();
    }

    public function addQuestions(Request $request, JobApplication $application, JobAnalysis $analysis): RedirectResponse
    {
        Gate::authorize('update', $application);

        $validated = $request->validate([
            'questions' => ['required', 'array', 'min:1', 'max:50'],
            'questions.*' => ['integer', 'min:0'],
        ]);

        $added = $this->analyses->addQuestions($analysis->setRelation('application', $application), array_values(array_map(intval(...), $validated['questions'])), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('{0} Those questions are already on the application.|{1} Added 1 question.|[2,*] Added :count questions.', $added, ['count' => $added])]);

        return back();
    }
}
