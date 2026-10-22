<?php

namespace App\Http\Controllers\Applications;

use App\Domain\Applications\ClarificationRequest;
use App\Domain\Applications\JobAnalysis;
use App\Domain\Applications\JobAnalysisService;
use App\Domain\Applications\JobApplication;
use App\Domain\Knowledge\ClaimSubject;
use App\Domain\Knowledge\Employment;
use App\Domain\Knowledge\Project;
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

        $existingQuestions = array_map(
            fn (string $text): string => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text))),
            $application->questions()->pluck('prompt_text')->all(),
        );

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
                'error_message' => $selected->error_message,
                'created_at' => $selected->created_at?->toIso8601String(),
                'completed_at' => $selected->completed_at?->toIso8601String(),
                'requested_by' => $selected->requester?->name,
                'model' => $selected->generationRun === null ? null : "{$selected->generationRun->provider}/{$selected->generationRun->model}",
                'latency_ms' => $selected->generationRun?->latency_ms,
                'is_latest_posting' => $selected->job_posting_id === $application->posting?->id,
                'question_already_added' => array_map(
                    fn (mixed $question): bool => in_array(mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $question))), $existingQuestions, true),
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
            'subjects' => self::subjectOptions(),
            'canUpdate' => $request->user()->can('update', $application),
        ]);
    }

    /**
     * Where an answered gap's bullet point can go: general, or one of the jobs or projects.
     *
     * @return list<array{value: string, label: string}>
     */
    private static function subjectOptions(): array
    {
        $options = [['value' => 'general', 'label' => __('General (not tied to a job or project)')]];

        foreach (Employment::query()->notArchived()->orderByDesc('start_date')->get() as $employment) {
            $options[] = ['value' => ClaimSubject::key($employment), 'label' => "{$employment->title} — {$employment->employer_name}"];
        }

        foreach (Project::query()->notArchived()->orderByDesc('start_date')->get() as $project) {
            $options[] = ['value' => ClaimSubject::key($project), 'label' => __('Project: :name', ['name' => $project->name])];
        }

        return $options;
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

        $this->analyses->refreshMatches($analysis->setRelation('application', $application));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Evidence refreshed from your current library.')]);

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
