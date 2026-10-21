<?php

namespace App\Http\Controllers\Profile;

use App\Domain\Knowledge\CandidateLanguage;
use App\Domain\Knowledge\CandidateLink;
use App\Domain\Knowledge\CandidateProfile;
use App\Domain\Knowledge\Claim;
use App\Domain\Knowledge\ClaimService;
use App\Domain\Knowledge\ClaimStatus;
use App\Domain\Knowledge\Credential;
use App\Domain\Knowledge\CredentialType;
use App\Domain\Knowledge\Employment;
use App\Domain\Knowledge\Project;
use App\Domain\Knowledge\Skill;
use App\Domain\Resumes\ResumeDocumentValidator;
use App\Domain\Resumes\ResumeService;
use App\Domain\Resumes\ResumeUsage;
use App\Domain\Resumes\ResumeVariant;
use App\Domain\Resumes\ResumeVariantRevision;
use App\Domain\Shared\PlainText;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Knowledge\SkillController;
use App\Http\Requests\Profile\SaveResumeRequest;
use App\Http\Resources\ClaimResource;
use App\Http\Resources\CredentialResource;
use App\Http\Resources\EmploymentResource;
use App\Http\Resources\ProjectResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Profile: the candidate's resumes. Everyone in the workspace can read them; only the
 * candidate edits (route middleware `can:edit-resumes` on writes).
 */
class ResumeController extends Controller
{
    public function __construct(private ResumeService $resumes) {}

    /**
     * Contact details and the list of resumes.
     */
    public function index(Request $request): Response
    {
        $resumes = ResumeVariant::query()
            ->with('currentRevision')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return Inertia::render('profile/index', [
            'contact' => ContactController::contactOf(CandidateProfile::current()),
            'resumes' => $resumes->map(fn (ResumeVariant $resume): array => [
                'ulid' => $resume->ulid,
                'name' => $resume->name,
                'title' => $resume->document()['title'] ?? null,
                'jobs' => count($resume->document()['experience'] ?? []),
                'is_default' => $resume->is_default,
                'is_archived' => $resume->isArchived(),
                'updated_at' => $resume->updated_at?->toIso8601String(),
            ])->values()->all(),
            'canEdit' => $request->user()->can('edit-resumes'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'copy_from' => ['nullable', 'string'],
        ]);

        $copyFrom = isset($validated['copy_from'])
            ? ResumeVariant::query()->where('ulid', $validated['copy_from'])->first()
            : null;

        $resume = $this->resumes->create(PlainText::normalize($validated['name']), $copyFrom, $request->user());

        return to_route('resumes.show', $resume);
    }

    /**
     * The resume editor (read-only preview for VAs).
     */
    public function show(Request $request, ResumeVariant $resume): Response
    {
        $resume->load('currentRevision');

        $revisions = ResumeVariantRevision::query()
            ->where('resume_variant_id', $resume->id)
            ->with('author:id,name')
            ->orderByDesc('revision_no')
            ->limit(30)
            ->get();

        return Inertia::render('profile/resume', [
            'resume' => [
                'ulid' => $resume->ulid,
                'name' => $resume->name,
                'is_default' => $resume->is_default,
                'is_archived' => $resume->isArchived(),
                'lock_version' => $resume->lock_version,
                'revision_no' => $resume->currentRevision?->revision_no,
                'document' => $resume->document(),
            ],
            'contact' => ContactController::contactOf(CandidateProfile::current()),
            'library' => self::editorLibrary(),
            'usedElsewhere' => ResumeUsage::map(exceptResumeId: $resume->id),
            'revisions' => $revisions->map(fn (ResumeVariantRevision $revision): array => [
                'id' => $revision->id,
                'revision_no' => $revision->revision_no,
                'created_at' => $revision->created_at->toIso8601String(),
                'author' => $revision->author?->name,
                'created_via' => $revision->created_via,
            ])->values()->all(),
            'canEdit' => $request->user()->can('edit-resumes') && ! $resume->isArchived(),
        ]);
    }

    /**
     * Everything a resume editor can pick from: live jobs with their projects and bullet points,
     * standalone projects, plus the shared sections. Also the source of tailored resumes' facts
     * snapshots.
     *
     * @return array<string, mixed>
     */
    public static function editorLibrary(): array
    {
        $liveClaims = fn ($query) => $query->whereIn('status', ClaimStatus::live())->with('currentRevision');

        $employments = Employment::query()
            ->notArchived()
            ->with([
                'claims' => $liveClaims,
                'projects' => fn ($query) => $query->notArchived()->with(['employment', 'claims' => $liveClaims])->orderByDesc('start_date'),
            ])
            ->orderByRaw('end_date IS NOT NULL, end_date DESC')
            ->orderByDesc('start_date')
            ->get();

        $standaloneProjects = Project::query()
            ->notArchived()
            ->whereNull('employment_id')
            ->with(['claims' => $liveClaims])
            ->orderByDesc('start_date')
            ->get();

        return [
            'employments' => EmploymentResource::collection($employments)->resolve(),
            'standaloneProjects' => ProjectResource::collection($standaloneProjects)->resolve(),
            ...self::sharedLibrary(),
        ];
    }

    /**
     * Education, certifications, skills, languages and links the resume can pick from.
     *
     * @return array{education: array<int, mixed>, certifications: array<int, mixed>, skills: list<array{ulid: string, name: string}>, languages: list<array{ulid: string, language: string, proficiency: string}>, links: list<array{ulid: string, kind: string, label: string, url: string}>}
     */
    public static function sharedLibrary(): array
    {
        $credentials = Credential::query()->notArchived()->orderByRaw('end_date IS NOT NULL, end_date DESC')->get();

        return [
            'education' => CredentialResource::collection($credentials->where('type', CredentialType::Education)->values())->resolve(),
            'certifications' => CredentialResource::collection($credentials->where('type', CredentialType::Certification)->values())->resolve(),
            'skills' => array_values(Skill::query()->alphabetical()->get()->map(SkillController::present(...))->all()),
            'languages' => array_values(CandidateLanguage::query()->orderBy('position')->get()->map(LanguageController::present(...))->all()),
            'links' => array_values(CandidateLink::query()->orderBy('position')->get()->map(LinkController::present(...))->all()),
        ];
    }

    public function update(SaveResumeRequest $request, ResumeVariant $resume): RedirectResponse
    {
        abort_if($resume->isArchived(), HttpResponse::HTTP_FORBIDDEN);

        $this->resumes->save($resume, $request->string('name')->toString(), $request->document(), $request->integer('lock_version'), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Resume saved.')]);

        return to_route('resumes.show', $resume);
    }

    public function makeDefault(Request $request, ResumeVariant $resume): RedirectResponse
    {
        abort_if($resume->isArchived(), HttpResponse::HTTP_FORBIDDEN);

        $this->resumes->makeDefault($resume, $request->user());

        return back();
    }

    public function archive(Request $request, ResumeVariant $resume): RedirectResponse
    {
        $this->resumes->archive($resume, $request->user());

        return to_route('resumes.index');
    }

    public function unarchive(Request $request, ResumeVariant $resume): RedirectResponse
    {
        $this->resumes->unarchive($resume, $request->user());

        return back();
    }

    /**
     * Make an older revision current again (as a new revision). Fails, with the reason, when it
     * points at library items that have been archived since.
     */
    public function restoreRevision(Request $request, ResumeVariant $resume, ResumeVariantRevision $revision, ResumeDocumentValidator $validator): RedirectResponse
    {
        abort_unless($revision->resume_variant_id === $resume->id, HttpResponse::HTTP_NOT_FOUND);
        abort_if($resume->isArchived(), HttpResponse::HTTP_FORBIDDEN);

        try {
            $document = $validator->validate($revision->document);
        } catch (ValidationException) {
            throw ValidationException::withMessages([
                'revision' => __('Revision :no uses experience, projects or bullet points that are no longer in your library.', ['no' => $revision->revision_no]),
            ]);
        }

        $this->resumes->save($resume, $resume->name, $document, $resume->lock_version, $request->user(), "restored:{$revision->revision_no}");

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Revision :no restored.', ['no' => $revision->revision_no])]);

        return to_route('resumes.show', $resume);
    }

    /**
     * "Apply everywhere": turn this resume's rewording of a bullet into the shared wording
     * (a new revision of the claim). JSON, so unsaved editor changes are kept.
     */
    public function applyBullet(Request $request, ResumeVariant $resume, Claim $claim, ClaimService $claims): JsonResponse
    {
        Gate::authorize('update', $claim);

        $validated = $request->validate(['text' => ['required', 'string', 'max:1000']]);

        $claim->load(['currentRevision', 'subject']);

        $claims->revise(
            $claim,
            PlainText::normalize($validated['text']),
            $claim->kind,
            __('Applied from resume ":name"', ['name' => $resume->name]),
            $claim->lock_version,
            $request->user(),
            allowNearDuplicate: true,
        );

        return response()->json(['claim' => ClaimResource::make($claim->refresh()->load('currentRevision'))->resolve($request)]);
    }
}
