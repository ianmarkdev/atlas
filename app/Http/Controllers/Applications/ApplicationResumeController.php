<?php

namespace App\Http\Controllers\Applications;

use App\Domain\Applications\ApplicationResume;
use App\Domain\Applications\ApplicationResumeService;
use App\Domain\Applications\ApplicationResumeVersion;
use App\Domain\Applications\JobApplication;
use App\Domain\Knowledge\CandidateProfile;
use App\Domain\Knowledge\Claim;
use App\Domain\Knowledge\ClaimService;
use App\Domain\Knowledge\ClaimStatus;
use App\Domain\Knowledge\ProposalService;
use App\Domain\Resumes\ResumeDiff;
use App\Domain\Resumes\ResumeDocumentValidator;
use App\Domain\Resumes\ResumeFacts;
use App\Domain\Resumes\ResumeUsage;
use App\Domain\Resumes\ResumeVariant;
use App\Domain\Resumes\ResumeVariantRevision;
use App\Domain\Shared\PlainText;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Profile\ContactController;
use App\Http\Controllers\Profile\ResumeController;
use App\Http\Requests\Applications\SaveApplicationResumeRequest;
use App\Http\Resources\ClaimResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The resume tailored for one application (M7a slice B): copied from a resume variant, edited
 * in the same editor, approved (frozen) by the candidate.
 */
class ApplicationResumeController extends Controller
{
    public function __construct(private ApplicationResumeService $resumes, private ResumeDocumentValidator $validator) {}

    /**
     * Copy a resume variant into the application.
     */
    public function store(Request $request, JobApplication $application): RedirectResponse
    {
        Gate::authorize('update', $application);

        $variant = $this->variantFrom($request);
        [$document, $snapshot] = $this->documentOf($variant);

        $this->resumes->start($application, $variant, $document, $snapshot, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Copied ":name". Tailor it for this job, then save.', ['name' => $variant->name])]);

        return to_route('applications.resume.show', $application);
    }

    public function show(Request $request, JobApplication $application): Response
    {
        $resume = $this->resumeOf($application);
        $resume->load(['currentVersion', 'approvedVersion', 'basedOn', 'approver:id,name']);
        $application->load('posting');
        $user = $request->user();

        // An approved resume is shown with the facts it was approved with, even if the library changed.
        $frozen = $resume->isApproved() ? $resume->approvedVersion : null;
        $liveLibrary = ResumeController::editorLibrary();
        $document = $frozen !== null ? $frozen->document : $resume->document();
        $baseRevision = $resume->based_on_revision_id === null ? null : ResumeVariantRevision::query()->find($resume->based_on_revision_id);

        $versions = ApplicationResumeVersion::query()
            ->where('application_resume_id', $resume->id)
            ->with('author:id,name')
            ->orderByDesc('version_no')
            ->limit(30)
            ->get();

        return Inertia::render('applications/resume', [
            'application' => [
                'ulid' => $application->ulid,
                'company_name' => $application->company_name,
                'role_title' => $application->role_title,
                'job_description' => $application->jobDescription(),
            ],
            'resume' => [
                'ulid' => $resume->ulid,
                'status' => $resume->status,
                'lock_version' => $resume->lock_version,
                'version_no' => ($frozen ?? $resume->currentVersion)?->version_no,
                'document' => $document,
                'based_on' => $resume->basedOn === null ? null : ['ulid' => $resume->basedOn->ulid, 'name' => $resume->basedOn->name],
                'approved_at' => $resume->approved_at?->toIso8601String(),
                'approved_by' => $resume->approver?->name,
                'generation' => $resume->generation_status === null ? null : [
                    'status' => $resume->generation_status,
                    'error' => $resume->generation_error,
                    'requested_at' => $resume->generation_requested_at?->toIso8601String(),
                ],
                // What the server refused from the AI's proposal, shown while that version is current.
                'ai_report' => $frozen === null && $resume->currentVersion?->author_type === 'ai' ? $resume->currentVersion->ai_report : null,
            ],
            'hasAnalysis' => $application->analyses()->where('status', 'succeeded')->exists(),
            'contact' => $frozen !== null ? $frozen->facts_snapshot['contact'] : ContactController::contactOf(CandidateProfile::current()),
            'library' => $frozen !== null ? $frozen->facts_snapshot['library'] : $liveLibrary,
            // What this tailored version changed compared with the resume revision it was copied from.
            'changes' => $baseRevision === null ? null : app(ResumeDiff::class)->between(
                [...ResumeDocumentValidator::emptyDocument(), ...$baseRevision->document],
                $document,
                $liveLibrary,
            ),
            'baseRevisionNo' => $baseRevision?->revision_no,
            'usedElsewhere' => ResumeUsage::map(),
            'versions' => $versions->map(fn (ApplicationResumeVersion $version): array => [
                'id' => $version->id,
                'version_no' => $version->version_no,
                'created_at' => $version->created_at->toIso8601String(),
                'author' => $version->author?->name,
                'created_via' => $version->created_via,
                'is_approved' => $version->id === $resume->approved_version_id,
            ])->values()->all(),
            'variants' => ResumeVariant::query()->active()->orderByDesc('is_default')->orderBy('name')->get(['id', 'ulid', 'name'])
                ->map(fn (ResumeVariant $variant): array => ['value' => $variant->ulid, 'label' => $variant->name])->values()->all(),
            'can' => [
                'update' => $user->can('update', $resume),
                'approve' => $user->can('approve', $resume),
                'reopen' => $user->can('reopen', $resume),
                'saveBack' => $user->can('saveBack', $resume),
                'headlineToVariant' => $resume->basedOn !== null && ! $resume->basedOn->isArchived() && $user->can('update', $application),
                'editDisplayTitles' => $user->isCandidate(),
            ],
        ]);
    }

    public function update(SaveApplicationResumeRequest $request, JobApplication $application): RedirectResponse
    {
        $resume = $this->resumeOf($application);
        Gate::authorize('update', $resume);

        $document = $request->document();
        $this->resumes->save($resume, $document, $this->snapshotOf($document), $request->integer('lock_version'), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Resume saved.')]);

        return to_route('applications.resume.show', $application);
    }

    /**
     * Replace the content with a fresh copy of a resume variant (kept as a new version).
     */
    public function copy(Request $request, JobApplication $application): RedirectResponse
    {
        $resume = $this->resumeOf($application);
        Gate::authorize('update', $resume);

        $variant = $this->variantFrom($request);
        [$document, $snapshot] = $this->documentOf($variant);

        $this->resumes->copyFrom($resume, $variant, $document, $snapshot, $request->integer('lock_version'), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Copied ":name" again. The earlier version is in the history.', ['name' => $variant->name])]);

        return to_route('applications.resume.show', $application);
    }

    /**
     * Queue AI tailoring (M8). The result arrives as a new version; nothing is overwritten.
     */
    public function tailor(Request $request, JobApplication $application): RedirectResponse
    {
        $resume = $this->resumeOf($application);
        Gate::authorize('update', $resume);

        $this->resumes->requestTailoring($resume, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Tailoring the resume for this job. The new version appears here when ready.')]);

        return back();
    }

    public function approve(Request $request, JobApplication $application): RedirectResponse
    {
        $resume = $this->resumeOf($application);
        Gate::authorize('approve', $resume);

        $this->resumes->approve($resume->load('currentVersion'), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Resume approved. It is frozen until you reopen it.')]);

        return back();
    }

    public function reopen(Request $request, JobApplication $application): RedirectResponse
    {
        $resume = $this->resumeOf($application);
        Gate::authorize('reopen', $resume);

        $this->resumes->reopen($resume, $request->user());

        return back();
    }

    /**
     * Write the tailored content back to the resume variant it was copied from.
     */
    public function saveBack(Request $request, JobApplication $application): RedirectResponse
    {
        $resume = $this->resumeOf($application);
        Gate::authorize('saveBack', $resume);

        $variant = $this->resumes->saveBackToVariant($resume->load(['currentVersion', 'basedOn']), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Saved to ":name" as a new revision.', ['name' => $variant->name])]);

        return back();
    }

    /**
     * "Apply everywhere" from the tailored resume: the rewording becomes the shared wording. A
     * VA's rewording of a verified bullet point is suggested to the candidate instead (M10).
     */
    public function applyBullet(Request $request, JobApplication $application, Claim $claim, ClaimService $claims, ProposalService $proposals): JsonResponse
    {
        Gate::authorize('update', $this->resumeOf($application));

        $validated = $request->validate(['text' => ['required', 'string', 'max:1000']]);
        $text = PlainText::normalize($validated['text']);
        $reason = __('Applied from the resume for :role at :company', ['role' => $application->role_title, 'company' => $application->companyLabel()]);

        if (! $request->user()->can('update', $claim)) {
            Gate::authorize('propose', $claim);

            $proposals->propose($claim, ['statement' => $text, 'kind' => $claim->kind->value, 'change_reason' => $reason], $reason, $claim->lock_version, $request->user());

            return response()->json(['claim' => ClaimResource::make($claim->load('currentRevision'))->resolve($request), 'proposed' => true]);
        }

        $claim->load(['currentRevision', 'subject']);

        $claims->revise($claim, $text, $claim->kind, $reason, $claim->lock_version, $request->user(), allowNearDuplicate: true);

        return response()->json(['claim' => ClaimResource::make($claim->refresh()->load('currentRevision'))->resolve($request), 'proposed' => false]);
    }

    /**
     * "Save as a new bullet point" from the tailored resume (M10): the rewording becomes a bullet
     * point of its own about the same job or project — verified for the candidate, proposed for
     * a VA — and the original stays as it is.
     */
    public function newBullet(Request $request, JobApplication $application, Claim $claim, ClaimService $claims): JsonResponse
    {
        Gate::authorize('update', $this->resumeOf($application));

        $validated = $request->validate(['text' => ['required', 'string', 'max:1000']]);
        $claim->load('subject');

        if (! in_array($claim->status, ClaimStatus::live(), true)) {
            throw ValidationException::withMessages(['text' => __('This bullet point is no longer in your library.')]);
        }

        $created = $claims->create(PlainText::normalize($validated['text']), $claim->kind, $claim->subject, $request->user(), allowNearDuplicate: true);

        return response()->json(['claim' => ClaimResource::make($created->load('currentRevision'))->resolve($request)]);
    }

    /**
     * Use the tailored title and summary on the resume variant (M10).
     */
    public function headlineToVariant(Request $request, JobApplication $application): RedirectResponse
    {
        $resume = $this->resumeOf($application);
        Gate::authorize('update', $application);

        $applied = $this->resumes->headlineToVariant($resume->load(['currentVersion', 'basedOn']), $request->user());
        $name = $resume->basedOn?->name;

        Inertia::flash('toast', ['type' => 'success', 'message' => $applied
            ? __('":name" now uses this title and summary.', ['name' => $name])
            : __('Suggested to the candidate: use this title and summary on ":name".', ['name' => $name])]);

        return back();
    }

    private function resumeOf(JobApplication $application): ApplicationResume
    {
        $resume = $application->resume;
        abort_if($resume === null, 404);
        $resume->setRelation('application', $application);

        return $resume;
    }

    private function variantFrom(Request $request): ResumeVariant
    {
        $validated = $request->validate([
            'variant' => ['required', 'string'],
            'lock_version' => ['sometimes', 'integer'],
        ]);

        $variant = ResumeVariant::query()->active()->where('ulid', $validated['variant'])->first();

        if ($variant === null) {
            throw ValidationException::withMessages(['variant' => __('Choose one of your resumes.')]);
        }

        return $variant->load('currentRevision');
    }

    /**
     * The variant's document checked against today's library, with its facts snapshot.
     *
     * @return array{array<string, mixed>, array<string, mixed>}
     */
    private function documentOf(ResumeVariant $variant): array
    {
        try {
            $document = $this->validator->validate($variant->document());
        } catch (ValidationException) {
            throw ValidationException::withMessages([
                'variant' => __('":name" uses experience, projects or bullet points that are no longer in your library. Open it on your Profile and save it first.', ['name' => $variant->name]),
            ]);
        }

        return [$document, $this->snapshotOf($document)];
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private function snapshotOf(array $document): array
    {
        return ResumeFacts::snapshot(ResumeController::editorLibrary(), ContactController::contactOf(CandidateProfile::current()), $document);
    }
}
