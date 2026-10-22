<?php

namespace App\Domain\Applications;

use App\Domain\Ai\GenerationRun;
use App\Domain\Audit\AuditLogger;
use App\Domain\Resumes\ResumeService;
use App\Domain\Resumes\ResumeVariant;
use App\Jobs\TailorApplicationResume;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Writes tailored resumes. Each save appends a version with a snapshot of the facts it shows.
 * Candidate and VAs both tailor; display titles (how a job title is shown) stay the candidate's
 * call, as on resume variants. Only the candidate approves, which freezes the resume.
 */
class ApplicationResumeService
{
    public function __construct(private AuditLogger $audit, private ResumeService $variants) {}

    /**
     * @param  array<string, mixed>  $document  the variant's document, validated
     * @param  array<string, mixed>  $snapshot
     *
     * @throws ValidationException when the application already has a tailored resume
     */
    public function start(JobApplication $application, ResumeVariant $variant, array $document, array $snapshot, User $actor): ApplicationResume
    {
        return DB::transaction(function () use ($application, $variant, $document, $snapshot, $actor): ApplicationResume {
            if (ApplicationResume::query()->where('job_application_id', $application->id)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['resume' => __('This application already has a tailored resume.')]);
            }

            $resume = new ApplicationResume;
            $resume->forceFill([
                'job_application_id' => $application->id,
                'based_on_variant_id' => $variant->id,
                'based_on_revision_id' => $variant->current_revision_id,
                'status' => 'draft',
                'lock_version' => 0,
            ])->save();

            $this->appendVersion($resume, $document, $snapshot, $actor, "copied:{$variant->id}");
            $resume->save();

            $this->audit->record('application_resume.created', $resume, ['variant' => $variant->ulid], $actor);

            return $resume;
        });
    }

    /**
     * Save the editor's state that was loaded at `$expectedLockVersion` as a new version.
     *
     * @param  array<string, mixed>  $document  normalised by ResumeDocumentValidator
     * @param  array<string, mixed>  $snapshot
     *
     * @throws ValidationException when the resume is approved, someone saved it in the meantime, or a VA changed a display title
     */
    public function save(ApplicationResume $resume, array $document, array $snapshot, int $expectedLockVersion, User $actor): void
    {
        DB::transaction(function () use ($resume, $document, $snapshot, $expectedLockVersion, $actor): void {
            $this->guardEditable($resume, $expectedLockVersion);
            $resume->loadMissing('currentVersion');

            if (! $actor->isCandidate() && self::displayTitles($document) !== self::displayTitles($resume->document())) {
                throw ValidationException::withMessages([
                    'document' => __('Only the candidate can change how a job title is shown ("Shown as").'),
                ]);
            }

            $this->appendVersion($resume, $document, $snapshot, $actor, 'manual');
            $resume->lock_version = $expectedLockVersion + 1;
            $resume->save();

            $this->audit->record('application_resume.saved', $resume, ['version_no' => $resume->currentVersion?->version_no], $actor);
        });
    }

    /**
     * Start over from a resume variant (the current one or another), as a new version.
     *
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $snapshot
     */
    public function copyFrom(ApplicationResume $resume, ResumeVariant $variant, array $document, array $snapshot, int $expectedLockVersion, User $actor): void
    {
        DB::transaction(function () use ($resume, $variant, $document, $snapshot, $expectedLockVersion, $actor): void {
            $this->guardEditable($resume, $expectedLockVersion);

            $resume->based_on_variant_id = $variant->id;
            $resume->based_on_revision_id = $variant->current_revision_id;
            $this->appendVersion($resume, $document, $snapshot, $actor, "copied:{$variant->id}");
            $resume->lock_version = $expectedLockVersion + 1;
            $resume->save();

            $this->audit->record('application_resume.copied', $resume, ['variant' => $variant->ulid], $actor);
        });
    }

    /**
     * @throws ValidationException while the resume shows facts nobody has verified yet
     */
    public function approve(ApplicationResume $resume, User $actor): void
    {
        $unverified = self::unverifiedFacts($resume->currentVersion->facts_snapshot ?? []);

        if ($unverified !== []) {
            throw ValidationException::withMessages([
                'resume' => __('Verify these in your library before approving: :items', ['items' => implode('; ', $unverified)]),
            ]);
        }

        DB::transaction(function () use ($resume, $actor): void {
            $resume->forceFill([
                'status' => 'approved',
                'approved_version_id' => $resume->current_version_id,
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'lock_version' => $resume->lock_version + 1,
            ])->save();

            $this->audit->record('application_resume.approved', $resume, ['version_no' => $resume->currentVersion?->version_no], $actor);
        });
    }

    /**
     * Allow edits again. The last approved version stays recorded until the next approval.
     */
    public function reopen(ApplicationResume $resume, User $actor): void
    {
        $resume->forceFill(['status' => 'draft', 'lock_version' => $resume->lock_version + 1])->save();

        $this->audit->record('application_resume.reopened', $resume, [], $actor);
    }

    /**
     * Make this tailored version the new content of the resume variant it came from.
     *
     * @throws ValidationException when the variant is gone or archived
     */
    public function saveBackToVariant(ApplicationResume $resume, User $actor): ResumeVariant
    {
        $variant = $resume->basedOn;

        if ($variant === null || $variant->isArchived()) {
            throw ValidationException::withMessages(['resume' => __('The resume this one was copied from no longer exists or is archived.')]);
        }

        $this->variants->save($variant, $variant->name, $resume->document(), $variant->lock_version, $actor, "from_application:{$resume->id}");

        return $variant;
    }

    /**
     * Queue AI tailoring (M8). The result arrives as a new version written by `ai`.
     *
     * @throws ValidationException when the resume is approved or is already being tailored
     */
    public function requestTailoring(ApplicationResume $resume, User $actor): void
    {
        DB::transaction(function () use ($resume, $actor): void {
            $current = ApplicationResume::query()->whereKey($resume->id)->lockForUpdate()->firstOrFail();

            if ($current->isApproved()) {
                throw ValidationException::withMessages(['resume' => __('This resume is approved. Reopen it before tailoring it again.')]);
            }

            if ($current->isGenerating()) {
                throw ValidationException::withMessages(['resume' => __('The resume is already being tailored.')]);
            }

            $current->forceFill([
                'generation_status' => 'queued',
                'generation_error' => null,
                'generation_requested_at' => now(),
                'generation_requested_by' => $actor->id,
            ])->save();

            $this->audit->record('application_resume.tailoring_requested', $current, [], $actor);

            TailorApplicationResume::dispatch($current->workspace_id, $current->id);
        });
    }

    /**
     * Store the tailored document as the resume's new current version, unless the resume was
     * approved or saved by someone while the AI worked on the version `$fromVersionId`.
     *
     * @param  array<string, mixed>  $document  validated
     * @param  array<string, mixed>  $snapshot
     * @param  array{refused: list<array{item: string, what: string, reason: string, kept: ?string}>, notes: list<string>}  $report
     */
    public function storeTailored(ApplicationResume $resume, int $fromVersionId, array $document, array $snapshot, GenerationRun $run, array $report, ?User $requester): void
    {
        DB::transaction(function () use ($resume, $fromVersionId, $document, $snapshot, $run, $report, $requester): void {
            $current = ApplicationResume::query()->whereKey($resume->id)->lockForUpdate()->firstOrFail();

            if ($current->isApproved() || $current->current_version_id !== $fromVersionId) {
                $this->failTailoring($current, __('The resume was saved or approved while the AI was tailoring it, so the AI version was discarded. Try again.'));

                return;
            }

            $this->appendVersion($current, $document, $snapshot, $requester, 'ai', authorType: 'ai', runId: $run->id, report: $report);
            $current->forceFill([
                'lock_version' => $current->lock_version + 1,
                'generation_status' => null,
                'generation_error' => null,
            ])->save();
        });
    }

    public function failTailoring(ApplicationResume $resume, string $message): void
    {
        $resume->forceFill(['generation_status' => 'failed', 'generation_error' => mb_strimwidth($message, 0, 1000, '…')])->save();
    }

    private function guardEditable(ApplicationResume $resume, int $expectedLockVersion): void
    {
        $current = ApplicationResume::query()->whereKey($resume->id)->lockForUpdate()->firstOrFail();

        if ($current->isApproved()) {
            throw ValidationException::withMessages(['resume' => __('This resume is approved. Reopen it to make changes.')]);
        }

        if ($current->lock_version !== $expectedLockVersion) {
            throw ValidationException::withMessages([
                'lock_version' => __('This resume was saved in another tab or by someone else. Reload to see that version, then apply your changes again.'),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>|null  $report
     */
    private function appendVersion(ApplicationResume $resume, array $document, array $snapshot, ?User $actor, string $via, string $authorType = 'user', ?int $runId = null, ?array $report = null): void
    {
        $nextNumber = (int) ApplicationResumeVersion::query()->where('application_resume_id', $resume->id)->max('version_no') + 1;

        $version = ApplicationResumeVersion::create([
            'application_resume_id' => $resume->id,
            'version_no' => $nextNumber,
            'document' => $document,
            'facts_snapshot' => $snapshot,
            'author_type' => $authorType,
            'author_user_id' => $actor?->id,
            'created_via' => $via,
            'parent_version_id' => $resume->current_version_id,
            'generation_run_id' => $runId,
            'ai_report' => $report,
        ]);

        $resume->current_version_id = $version->id;
        $resume->setRelation('currentVersion', $version);
    }

    /**
     * Labels of the snapshot's jobs, projects, bullet points and credentials that are not verified.
     *
     * @param  array<string, mixed>  $snapshot
     * @return list<string>
     */
    private static function unverifiedFacts(array $snapshot): array
    {
        $library = $snapshot['library'] ?? [];
        $labels = [];
        $check = function (array $item, string $label) use (&$labels): void {
            if (($item['status'] ?? 'verified') !== 'verified') {
                $labels[] = $label;
            }
        };
        $checkRecord = function (array $record, string $label) use ($check): void {
            $check($record, $label);

            foreach ($record['claims'] ?? [] as $claim) {
                $check($claim, (string) ($claim['statement'] ?? ''));
            }
        };

        foreach ($library['employments'] ?? [] as $employment) {
            $checkRecord($employment, "{$employment['title']} · {$employment['employer_name']}");

            foreach ($employment['projects'] ?? [] as $project) {
                $checkRecord($project, (string) $project['name']);
            }
        }

        foreach ($library['standaloneProjects'] ?? [] as $project) {
            $checkRecord($project, (string) $project['name']);
        }

        foreach ([...($library['education'] ?? []), ...($library['certifications'] ?? [])] as $credential) {
            $check($credential, (string) $credential['name']);
        }

        return $labels;
    }

    /**
     * Display title per experience on the resume (null = the official title).
     *
     * @param  array<string, mixed>  $document
     * @return array<string, string|null>
     */
    private static function displayTitles(array $document): array
    {
        $titles = [];

        foreach ($document['experience'] ?? [] as $job) {
            $titles[(string) ($job['employment'] ?? '')] = $job['display_title'] ?? null;
        }

        ksort($titles);

        return $titles;
    }
}
