<?php

namespace App\Http\Controllers\Knowledge;

use App\Concerns\ProposesKnowledgeChanges;
use App\Domain\Knowledge\Claim;
use App\Domain\Knowledge\ClaimKind;
use App\Domain\Knowledge\ClaimService;
use App\Domain\Knowledge\ClaimStatus;
use App\Domain\Knowledge\ClaimSubject;
use App\Domain\Knowledge\Employment;
use App\Domain\Knowledge\Project;
use App\Http\Controllers\Controller;
use App\Http\Requests\Knowledge\BulkStoreClaimsRequest;
use App\Http\Requests\Knowledge\SaveClaimRequest;
use App\Http\Resources\ClaimResource;
use App\Http\Resources\TextRevisionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ClaimController extends Controller
{
    use ProposesKnowledgeChanges;

    public function __construct(private ClaimService $claims) {}

    public function create(Request $request): Response
    {
        return Inertia::render('knowledge/claim-form', [
            'claim' => null,
            'subjectLabel' => null,
            'defaultSubject' => $this->defaultSubject($request),
            ...$this->formOptions(),
        ]);
    }

    /**
     * JSON requests (the resume editor's add-bullet field) get the new claim back instead of a redirect.
     */
    public function store(SaveClaimRequest $request): RedirectResponse|JsonResponse
    {
        $claim = $this->claims->create(
            $request->string('statement')->toString(),
            $request->enum('kind', ClaimKind::class) ?? ClaimKind::Other,
            $request->subjectRecord(),
            $request->user(),
            $request->boolean('allow_near_duplicate'),
        );

        if ($request->expectsJson()) {
            return response()->json(['claim' => ClaimResource::make($claim)->resolve($request)], 201);
        }

        $this->flashSaved($claim);

        return to_route('knowledge.index');
    }

    public function edit(Request $request, Claim $claim): Response
    {
        $proposing = $this->proposing($request->user(), $claim);

        $claim->load(['currentRevision', 'subject']);

        return Inertia::render('knowledge/claim-form', [
            'claim' => ClaimResource::make($claim)->resolve(),
            'subjectLabel' => $this->subjectLabel($claim->subject),
            'defaultSubject' => null,
            'proposing' => $proposing,
            'history' => TextRevisionResource::collection(
                $claim->revisions()->with('author:id,name')->reorder('revision_no', 'desc')->limit(30)->get(),
            )->resolve(),
            ...$this->formOptions(),
        ]);
    }

    public function update(SaveClaimRequest $request, Claim $claim): RedirectResponse
    {
        if ($this->proposing($request->user(), $claim)) {
            return $this->proposeChange($claim, [
                'statement' => $request->string('statement')->toString(),
                'kind' => ($request->enum('kind', ClaimKind::class) ?? $claim->kind)->value,
                'change_reason' => $request->input('change_reason'),
            ], $request);
        }

        $claim->load(['currentRevision', 'subject']);

        $this->claims->revise(
            $claim,
            $request->string('statement')->toString(),
            $request->enum('kind', ClaimKind::class) ?? $claim->kind,
            $request->input('change_reason'),
            $request->integer('lock_version'),
            $request->user(),
            $request->boolean('allow_near_duplicate'),
        );

        $this->flashSaved($claim);

        return to_route('knowledge.index');
    }

    public function bulkCreate(Request $request): Response
    {
        return Inertia::render('knowledge/claims-bulk', [
            'defaultSubject' => $this->defaultSubject($request),
            'maxLines' => BulkStoreClaimsRequest::MAX_LINES,
            ...$this->formOptions(),
        ]);
    }

    public function bulkStore(BulkStoreClaimsRequest $request): RedirectResponse|JsonResponse
    {
        $result = $this->claims->createMany(
            $request->statementLines(),
            $request->enum('kind', ClaimKind::class) ?? ClaimKind::Other,
            $request->subjectRecord(),
            $request->user(),
        );

        if ($request->expectsJson()) {
            return response()->json([
                'claims' => ClaimResource::collection($result['created'])->resolve($request),
                'skipped' => $result['skipped'],
            ], 201);
        }

        $createdCount = count($result['created']);
        $message = trans_choice('{1} Added 1 bullet point.|[0,*] Added :count bullet points.', $createdCount, ['count' => $createdCount]);

        if ($result['skipped'] > 0) {
            $message .= ' '.trans_choice('{1} Skipped 1 duplicate.|[2,*] Skipped :count duplicates.', $result['skipped'], ['count' => $result['skipped']]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('knowledge.index');
    }

    public function verify(Request $request, Claim $claim): RedirectResponse
    {
        Gate::authorize('verify', $claim);

        $this->claims->verify($claim->load('currentRevision'), $request->user());

        return back();
    }

    public function reject(Request $request, Claim $claim): RedirectResponse
    {
        Gate::authorize('reject', $claim);

        $this->claims->reject($claim, $request->user());

        return back();
    }

    public function archive(Request $request, Claim $claim): RedirectResponse
    {
        Gate::authorize('archive', $claim);

        $this->claims->archive($claim, $request->user());

        return back();
    }

    public function restore(Request $request, Claim $claim): RedirectResponse
    {
        Gate::authorize('restore', $claim);

        $this->claims->restore($claim->load(['currentRevision', 'subject']), $request->user());

        return back();
    }

    /**
     * @return array{kinds: list<array{value: string, label: string}>, subjects: list<array{value: string, label: string}>}
     */
    private function formOptions(): array
    {
        $subjects = [['value' => 'general', 'label' => __('General (not tied to an experience or project)')]];

        foreach (Employment::query()->notArchived()->orderByDesc('start_date')->get() as $employment) {
            $subjects[] = ['value' => ClaimSubject::key($employment), 'label' => $this->subjectLabel($employment)];
        }

        foreach (Project::query()->notArchived()->orderByDesc('start_date')->get() as $project) {
            $subjects[] = ['value' => ClaimSubject::key($project), 'label' => $this->subjectLabel($project)];
        }

        $kinds = [];

        foreach (ClaimKind::cases() as $kind) {
            $kinds[] = ['value' => $kind->value, 'label' => ucfirst($kind->value)];
        }

        return ['kinds' => $kinds, 'subjects' => $subjects];
    }

    private function subjectLabel(mixed $subject): string
    {
        return match (true) {
            $subject instanceof Employment => "{$subject->title} — {$subject->employer_name}",
            $subject instanceof Project => __('Project: :name', ['name' => $subject->name]),
            default => __('General'),
        };
    }

    private function defaultSubject(Request $request): string
    {
        $subject = ClaimSubject::resolve($request->string('subject')->toString());

        return $subject === false ? 'general' : ClaimSubject::key($subject);
    }

    private function flashSaved(Claim $claim): void
    {
        $message = $claim->status === ClaimStatus::Verified
            ? __('Claim saved.')
            : __('Claim saved as proposed. The candidate will verify it.');

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);
    }
}
