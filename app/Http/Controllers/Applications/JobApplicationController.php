<?php

namespace App\Http\Controllers\Applications;

use App\Domain\Applications\ApplicationQuestion;
use App\Domain\Applications\ApplicationReviewService;
use App\Domain\Applications\ApplicationStage;
use App\Domain\Applications\ApplyRequirement;
use App\Domain\Applications\JdCollection;
use App\Domain\Applications\JobApplication;
use App\Domain\Applications\JobApplicationService;
use App\Domain\Applications\ReviewStatus;
use App\Domain\Audit\ActivityFeed;
use App\Domain\Knowledge\EmploymentType;
use App\Domain\Resumes\ResumeVariant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Applications\SaveJobApplicationRequest;
use App\Http\Resources\JobApplicationResource;
use App\Models\User;
use Carbon\CarbonImmutable;
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
    /**
     * Quick filters of the list: everything, assigned to me, unassigned (open), waiting for the
     * candidate's review, sent back with changes, past the deadline, prepared in the background.
     */
    private const FILTERS = ['all', 'mine', 'unassigned', 'in_review', 'changes_requested', 'overdue', 'background'];

    public function __construct(private JobApplicationService $applications) {}

    /**
     * All applications (the board needs every stage), searchable by company and role; the
     * candidate can list recently deleted ones.
     */
    public function index(Request $request): Response
    {
        $search = trim($request->string('q')->toString());
        $showDeleted = $request->boolean('deleted') && $request->user()->isCandidate();
        $filter = in_array($request->query('filter'), self::FILTERS, true) ? (string) $request->query('filter') : 'all';
        $userId = $request->user()->id;
        $collection = $request->filled('collection')
            ? JdCollection::query()->where('ulid', $request->string('collection')->toString())->first()
            : null;

        $applications = JobApplication::query()
            ->with('assignee:id,ulid,name')
            ->when($showDeleted, fn (Builder $query) => $query->onlyTrashed())
            ->when($collection !== null, fn (Builder $query) => $query->whereHas('collections', fn (Builder $in) => $in->whereKey($collection?->id)))
            ->when($filter === 'mine', fn (Builder $query) => $query->where('assigned_user_id', $userId))
            ->when($filter === 'unassigned', fn (Builder $query) => $query->whereNull('assigned_user_id')->whereNotIn('stage', [ApplicationStage::Rejected, ApplicationStage::Withdrawn]))
            ->when($filter === 'in_review', fn (Builder $query) => $query->where('review_status', ReviewStatus::InReview))
            ->when($filter === 'changes_requested', fn (Builder $query) => $query->where('review_status', ReviewStatus::ChangesRequested))
            ->when($filter === 'overdue', fn (Builder $query) => $query
                ->whereIn('stage', [ApplicationStage::Saved, ApplicationStage::Preparing])
                ->whereDate('deadline_on', '<', CarbonImmutable::today()))
            ->when($filter === 'background', fn (Builder $query) => $query->whereNotNull('autopilot_status'))
            ->when($search !== '', function (Builder $query) use ($search): void {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $query->where(fn (Builder $inner) => $inner
                    ->where('company_name', 'ilike', $like)
                    ->orWhere('role_title', 'ilike', $like));
            })
            ->tap(self::inListOrder(...))
            ->get();

        return Inertia::render('applications/index', [
            'applications' => JobApplicationResource::collection($applications)->resolve(),
            'filters' => ['q' => $search, 'deleted' => $showDeleted, 'filter' => $filter],
            'collection' => $collection === null ? null : ['ulid' => $collection->ulid, 'name' => $collection->name],
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

    public function show(Request $request, JobApplication $application, ApplicationReviewService $reviews): Response
    {
        $application->load(['posting', 'owner:id,name', 'assignee:id,ulid,name', 'resume.currentVersion', 'resume.basedOn', 'questions.answer.currentVersion', 'latestAnalysis', 'collections']);
        $resume = $application->resume;
        $questions = $application->questions;
        $user = $request->user();

        return Inertia::render('applications/show', [
            'application' => [
                ...JobApplicationResource::make($application)->resolve(),
                'notes' => $application->notes,
                'proposal_angle' => $application->proposal_angle,
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
            'analysisSummary' => self::analysisSummary($application),
            'collections' => [
                'member' => $application->collections->map(fn (JdCollection $collection): array => ['ulid' => $collection->ulid, 'name' => $collection->name, 'use_for_eval' => $collection->use_for_eval])->values()->all(),
                'all' => JdCollection::query()->orderBy('name')->get(['id', 'ulid', 'name'])->map(fn (JdCollection $collection): array => ['value' => $collection->ulid, 'label' => $collection->name])->values()->all(),
            ],
            'questionsSummary' => [
                'total' => $questions->count(),
                'answered' => $questions->filter(fn (ApplicationQuestion $question): bool => trim($question->answer?->body() ?? '') !== '')->count(),
                'approved' => $questions->filter(fn (ApplicationQuestion $question): bool => $question->answer?->isApproved() ?? false)->count(),
                'has_cover_letter' => $questions->contains(fn (ApplicationQuestion $question): bool => $question->isCoverLetter()),
            ],
            'variants' => ResumeVariant::query()->active()->orderByDesc('is_default')->orderBy('name')->get(['ulid', 'name'])
                ->map(fn (ResumeVariant $variant): array => ['value' => $variant->ulid, 'label' => $variant->name])->values()->all(),
            'resumeRanking' => $resume === null ? self::resumeRanking($application) : null,
            'review' => [
                'me' => $user->ulid,
                'missing' => $reviews->notYetApproved($application),
                'team' => $user->isCandidate() ? $this->assignableMembers($application) : [],
                'can' => [
                    'assign' => $user->can('assign', [$application, null]) && $user->isCandidate(),
                    'take' => $user->can('assign', [$application, $user]) && ! $user->isCandidate(),
                    'handBack' => ! $user->isCandidate() && $user->can('assign', [$application, null]),
                    'start' => $user->can('update', $application),
                    'submit' => $user->can('submitForReview', $application),
                    'review' => $user->can('review', $application),
                ],
            ],
            'timeline' => ActivityFeed::recent($user, 30, fn (Builder $query) => $query->where(function (Builder $events) use ($application, $resume, $questions): void {
                $events->where(fn (Builder $q) => $q->where('subject_type', 'job_application')->where('subject_id', $application->id));

                if ($resume !== null) {
                    $events->orWhere(fn (Builder $q) => $q->where('subject_type', 'application_resume')->where('subject_id', $resume->id));
                }

                if ($questions->isNotEmpty()) {
                    $events->orWhere(fn (Builder $q) => $q->where('subject_type', 'application_question')->whereIn('subject_id', $questions->modelKeys()));
                }
            })),
            'neighbours' => self::neighboursOf($application),
            'stages' => self::stageOptions(),
            'employmentTypes' => self::employmentTypeOptions(),
            'applyRequirements' => self::applyRequirementOptions(),
        ]);
    }

    /**
     * The list's order: the newest application first.
     *
     * @param  Builder<JobApplication>  $query
     */
    private static function inListOrder(Builder $query): void
    {
        $query->latest('created_at')->orderByDesc('id');
    }

    /**
     * The applications before and after this one in the list, for stepping through them.
     *
     * @return array{position: int, total: int, previous: ?array{ulid: string, label: string}, next: ?array{ulid: string, label: string}}|null
     */
    private static function neighboursOf(JobApplication $application): ?array
    {
        $ulids = JobApplication::query()->tap(self::inListOrder(...))->pluck('ulid')->all();
        $position = array_search($application->ulid, $ulids, true);

        if ($position === false) {
            return null;
        }

        $neighbours = JobApplication::query()
            ->whereIn('ulid', array_filter([$ulids[$position - 1] ?? null, $ulids[$position + 1] ?? null]))
            ->get(['ulid', 'company_name', 'role_title'])
            ->keyBy('ulid');
        $summary = fn (?string $ulid): ?array => $ulid === null || ! $neighbours->has($ulid) ? null : [
            'ulid' => $ulid,
            'label' => $neighbours[$ulid]->role_title.' · '.$neighbours[$ulid]->companyLabel(),
        ];

        return [
            'position' => $position + 1,
            'total' => count($ulids),
            'previous' => $summary($ulids[$position - 1] ?? null),
            'next' => $summary($ulids[$position + 1] ?? null),
        ];
    }

    public function edit(JobApplication $application): Response
    {
        Gate::authorize('update', $application);

        $application->load('posting');

        return Inertia::render('applications/form', [
            'application' => [
                ...JobApplicationResource::make($application)->resolve(),
                'notes' => $application->notes,
                'proposal_angle' => $application->proposal_angle,
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
     * The latest AI analysis in brief, for the application page card.
     *
     * @return array{status: string, created_at: ?string, must_haves: int, strong: int, gaps: int, injection_warnings: int, error_message: ?string, is_latest_posting: bool}|null
     */
    private static function analysisSummary(JobApplication $application): ?array
    {
        $analysis = $application->latestAnalysis;

        if ($analysis === null) {
            return null;
        }

        $musts = array_values(array_filter((array) ($analysis->matches['requirements'] ?? []), fn (mixed $row): bool => is_array($row) && ($row['kind'] ?? null) === 'must'));
        $coverage = array_count_values(array_map(fn (array $row): string => (string) ($row['coverage'] ?? ''), $musts));

        return [
            'status' => $analysis->status,
            'created_at' => $analysis->created_at?->toIso8601String(),
            'must_haves' => count($musts),
            'strong' => $coverage['strong'] ?? 0,
            'gaps' => $coverage['gap'] ?? 0,
            // The server's findings only: the model's own warnings are hints, shown on the analysis page.
            'injection_warnings' => count((array) ($analysis->brief['server_injection_warnings'] ?? [])),
            'error_message' => $analysis->error_message,
            'is_latest_posting' => $analysis->job_posting_id === $application->posting?->id,
        ];
    }

    /**
     * The latest analysis's resume ranking, for choosing the resume to start from. Stale when a
     * resume was edited, added or archived since: the ranking read other bullet points.
     *
     * @return array{resumes: list<array<string, mixed>>, stale: bool}|null
     */
    private static function resumeRanking(JobApplication $application): ?array
    {
        $ranking = $application->latestAnalysis?->status === 'succeeded' ? $application->latestAnalysis->resume_ranking : null;

        if ($ranking === null || $ranking['resumes'] === []) {
            return null;
        }

        $current = ResumeVariant::query()->active()->pluck('current_revision_id', 'ulid')->all();
        $ranked = array_column($ranking['resumes'], 'revision_id', 'ulid');
        ksort($current);
        ksort($ranked);

        return [
            'resumes' => array_map(fn (array $resume): array => array_diff_key($resume, ['revision_id' => true]), $ranking['resumes']),
            'stale' => $current !== $ranked,
        ];
    }

    /**
     * Active VAs and the candidate, for the assignee picker.
     *
     * @return list<array{value: string, label: string}>
     */
    private function assignableMembers(JobApplication $application): array
    {
        $members = User::query()
            ->whereHas('membership', fn ($query) => $query->where('workspace_id', $application->workspace_id))
            ->where('is_active', true)
            ->with('membership')
            ->orderBy('name')
            ->get()
            ->filter(fn (User $member): bool => ApplicationReviewService::canBeAssigned($member));

        return array_values(array_map(fn (User $member): array => [
            'value' => $member->ulid,
            'label' => $member->isCandidate() ? "{$member->name} (candidate)" : $member->name,
        ], $members->all()));
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
     * @return list<array{value: string, label: string}>
     */
    private static function applyRequirementOptions(): array
    {
        return array_map(
            fn (ApplyRequirement $requirement): array => ['value' => $requirement->value, 'label' => $requirement->label()],
            ApplyRequirement::cases(),
        );
    }

    /**
     * @return array{stages: list<array{value: string, label: string}>, employmentTypes: list<array{value: string, label: string}>, applyRequirements: list<array{value: string, label: string}>}
     */
    private function formOptions(): array
    {
        return ['stages' => self::stageOptions(), 'employmentTypes' => self::employmentTypeOptions(), 'applyRequirements' => self::applyRequirementOptions()];
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
