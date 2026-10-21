<?php

namespace App\Http\Controllers\Applications;

use App\Domain\Applications\ApplicationStage;
use App\Domain\Applications\JobApplication;
use App\Domain\Applications\JobApplicationService;
use App\Domain\Knowledge\EmploymentType;
use App\Domain\Resumes\ResumeVariant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Applications\SaveJobApplicationRequest;
use App\Http\Resources\JobApplicationResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The applications tracker (M7a slice A): a board by stage and a table, plus add/edit/detail.
 */
class JobApplicationController extends Controller
{
    public function __construct(private JobApplicationService $applications) {}

    /**
     * All applications (the board needs every stage), searchable by company and role; the
     * candidate can list recently deleted ones.
     */
    public function index(Request $request): Response
    {
        $search = trim($request->string('q')->toString());
        $showDeleted = $request->boolean('deleted') && $request->user()->isCandidate();

        $applications = JobApplication::query()
            ->when($showDeleted, fn (Builder $query) => $query->onlyTrashed())
            ->when($search !== '', function (Builder $query) use ($search): void {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $query->where(fn (Builder $inner) => $inner
                    ->where('company_name', 'ilike', $like)
                    ->orWhere('role_title', 'ilike', $like));
            })
            ->orderByRaw('deadline_on IS NULL, deadline_on')
            ->latest('updated_at')
            ->get();

        return Inertia::render('applications/index', [
            'applications' => JobApplicationResource::collection($applications)->resolve(),
            'filters' => ['q' => $search, 'deleted' => $showDeleted],
            'stages' => self::stageOptions(),
            'canViewDeleted' => $request->user()->isCandidate(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('applications/form', [
            'application' => null,
            'jobDescription' => null,
            ...$this->formOptions(),
        ]);
    }

    public function store(SaveJobApplicationRequest $request): RedirectResponse
    {
        $application = $this->applications->create(
            $request->details(),
            $request->jobDescription(),
            $request->enum('stage', ApplicationStage::class) ?? ApplicationStage::Saved,
            $request->user(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Application added.')]);

        return to_route('applications.show', $application);
    }

    public function show(JobApplication $application): Response
    {
        $application->load(['posting', 'owner:id,name', 'resume.currentVersion', 'resume.basedOn']);
        $resume = $application->resume;

        return Inertia::render('applications/show', [
            'application' => [
                ...JobApplicationResource::make($application)->resolve(),
                'notes' => $application->notes,
                'owner' => $application->owner?->name,
            ],
            'jobDescription' => $this->jobDescriptionOf($application),
            'tailoredResume' => $resume === null ? null : [
                'status' => $resume->status,
                'version_no' => $resume->currentVersion?->version_no,
                'title' => $resume->document()['title'] ?: null,
                'jobs' => count($resume->document()['experience']),
                'based_on' => $resume->basedOn?->name,
                'updated_at' => $resume->updated_at?->toIso8601String(),
            ],
            'variants' => ResumeVariant::query()->active()->orderByDesc('is_default')->orderBy('name')->get(['ulid', 'name'])
                ->map(fn (ResumeVariant $variant): array => ['value' => $variant->ulid, 'label' => $variant->name])->values()->all(),
            'stages' => self::stageOptions(),
            'employmentTypes' => self::employmentTypeOptions(),
        ]);
    }

    public function edit(JobApplication $application): Response
    {
        Gate::authorize('update', $application);

        $application->load('posting');

        return Inertia::render('applications/form', [
            'application' => [
                ...JobApplicationResource::make($application)->resolve(),
                'notes' => $application->notes,
            ],
            'jobDescription' => $application->jobDescription(),
            ...$this->formOptions(),
        ]);
    }

    public function update(SaveJobApplicationRequest $request, JobApplication $application): RedirectResponse
    {
        Gate::authorize('update', $application);

        $this->applications->update(
            $application,
            $request->details(),
            $request->jobDescription(),
            $request->integer('lock_version'),
            $request->user(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Application saved.')]);

        return to_route('applications.show', $application);
    }

    /**
     * Move an application to another stage (board select, detail page).
     */
    public function stage(Request $request, JobApplication $application): RedirectResponse
    {
        Gate::authorize('update', $application);

        $validated = $request->validate(['stage' => ['required', Rule::enum(ApplicationStage::class)]]);

        $this->applications->changeStage($application, ApplicationStage::from($validated['stage']), $request->user());

        return back();
    }

    public function destroy(Request $request, JobApplication $application): RedirectResponse
    {
        Gate::authorize('delete', $application);

        $this->applications->delete($application, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Application deleted. You can restore it from “Recently deleted”.')]);

        return to_route('applications.index');
    }

    public function restore(Request $request, JobApplication $application): RedirectResponse
    {
        Gate::authorize('restore', $application);

        $this->applications->restore($application, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Application restored.')]);

        return to_route('applications.show', $application);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function stageOptions(): array
    {
        return array_map(
            fn (ApplicationStage $stage): array => ['value' => $stage->value, 'label' => $stage->label()],
            ApplicationStage::cases(),
        );
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private static function employmentTypeOptions(): array
    {
        return array_map(
            fn (EmploymentType $type): array => ['value' => $type->value, 'label' => ucfirst(str_replace('_', ' ', $type->value))],
            EmploymentType::cases(),
        );
    }

    /**
     * @return array{stages: list<array{value: string, label: string}>, employmentTypes: list<array{value: string, label: string}>}
     */
    private function formOptions(): array
    {
        return ['stages' => self::stageOptions(), 'employmentTypes' => self::employmentTypeOptions()];
    }

    /**
     * @return array{text: string, captured_at: string, versions: int}|null
     */
    private function jobDescriptionOf(JobApplication $application): ?array
    {
        $posting = $application->posting;

        if ($posting === null) {
            return null;
        }

        return [
            'text' => $posting->raw_text,
            'captured_at' => $posting->captured_at->toIso8601String(),
            'versions' => $application->postings()->count(),
        ];
    }
}
