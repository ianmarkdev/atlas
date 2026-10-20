<?php

namespace App\Http\Controllers\Knowledge;

use App\Domain\Knowledge\Employment;
use App\Domain\Knowledge\KnowledgeRecordService;
use App\Domain\Knowledge\Project;
use App\Http\Controllers\Controller;
use App\Http\Requests\Knowledge\SaveProjectRequest;
use App\Http\Resources\ProjectResource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function __construct(private KnowledgeRecordService $records) {}

    public function create(Request $request): Response
    {
        return Inertia::render('knowledge/project-form', [
            'project' => null,
            'employments' => $this->employmentOptions(),
            'defaultEmployment' => $request->string('employment')->toString() ?: null,
        ]);
    }

    public function store(SaveProjectRequest $request): RedirectResponse
    {
        $project = $this->records->create(new Project($request->projectAttributes()), $request->user());

        $this->flashSaved($project);

        return to_route('knowledge.index');
    }

    public function edit(Project $project): Response
    {
        Gate::authorize('update', $project);

        return Inertia::render('knowledge/project-form', [
            'project' => ProjectResource::make($project->load('employment'))->resolve(),
            'employments' => $this->employmentOptions(),
            'defaultEmployment' => null,
        ]);
    }

    public function update(SaveProjectRequest $request, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);

        $this->records->update($project, $request->projectAttributes(), $request->integer('lock_version'), $request->user());

        $this->flashSaved($project);

        return to_route('knowledge.index');
    }

    public function verify(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('verify', $project);

        $this->records->verify($project, $request->user());

        return to_route('knowledge.index');
    }

    public function archive(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('archive', $project);

        $this->records->archive($project, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Project archived.')]);

        return to_route('knowledge.index');
    }

    public function restore(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('restore', $project);

        $this->records->restore($project, $request->user());

        return to_route('knowledge.index', ['archived' => 1]);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function employmentOptions(): array
    {
        $employments = Employment::query()
            ->notArchived()
            ->orderByDesc('start_date')
            ->get(['ulid', 'employer_name', 'title']);

        return array_values($employments->map(fn (Employment $employment): array => [
            'value' => $employment->ulid,
            'label' => "{$employment->title} — {$employment->employer_name}",
        ])->all());
    }

    private function flashSaved(Project $project): void
    {
        $message = $project->isVerified()
            ? __('Project saved.')
            : __('Project saved as proposed. The candidate will verify it.');

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);
    }
}
