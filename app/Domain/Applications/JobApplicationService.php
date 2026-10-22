<?php

namespace App\Domain\Applications;

use App\Domain\Audit\AuditLogger;
use App\Domain\Knowledge\CandidateProfile;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Writes applications. Candidate and VAs both add and prepare applications; the job description
 * is kept verbatim, and a corrected paste is stored next to the earlier one.
 *
 * @phpstan-type ApplicationDetails array{company_name: string, role_title: string, source_url: ?string, location: ?string, employment_type: ?string, salary_text: ?string, deadline_on: ?string, notes: ?string}
 */
class JobApplicationService
{
    public function __construct(private AuditLogger $audit, private DuplicateApplicationDetector $duplicates) {}

    /**
     * @param  ApplicationDetails  $details
     */
    public function create(array $details, ?string $jobDescription, ApplicationStage $stage, User $actor): JobApplication
    {
        return DB::transaction(function () use ($details, $jobDescription, $stage, $actor): JobApplication {
            $application = new JobApplication;
            $application->forceFill([
                ...$details,
                'candidate_profile_id' => CandidateProfile::current()->id,
                'stage' => ApplicationStage::Saved,
                'review_status' => ReviewStatus::NotStarted,
                'owner_user_id' => $actor->id,
                'lock_version' => 0,
            ]);
            $this->applyStage($application, $stage);
            $application->save();

            $this->storeJobDescription($application, $jobDescription, $actor);

            $this->audit->record('application.created', $application, ['stage' => $application->stage->value], $actor);

            return $application;
        });
    }

    /**
     * Save a job listing captured by the browser extension as a new application in `saved`.
     * An existing application for the same listing is never changed: the capture is refused
     * unless `$allowDuplicate`. `$capture` holds what the extension found besides the form
     * fields (extraction method, site, listing id, structured salary…), kept in the audit log.
     *
     * @param  ApplicationDetails  $details
     * @param  array<string, mixed>  $capture
     *
     * @throws DuplicateApplication
     */
    public function capture(array $details, ?string $jobDescription, array $capture, bool $allowDuplicate, User $actor): JobApplication
    {
        return DB::transaction(function () use ($details, $jobDescription, $capture, $allowDuplicate, $actor): JobApplication {
            // One capture at a time per workspace, so a double click cannot save the listing twice.
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?), ?::int)', ['job_applications.capture', app(TenantContext::class)->workspaceId()]);

            $existing = $details['source_url'] === null ? null : $this->duplicates->forSourceUrl($details['source_url']);

            if ($existing !== null && ! $allowDuplicate) {
                throw new DuplicateApplication($existing);
            }

            $application = $this->create($details, $jobDescription, ApplicationStage::Saved, $actor);

            $this->audit->record('application.captured', $application, [
                ...$capture,
                'duplicate_of' => $existing?->ulid,
            ], $actor);

            return $application;
        });
    }

    /**
     * Apply an edit made from a form loaded at `$expectedLockVersion`.
     *
     * @param  ApplicationDetails  $details
     *
     * @throws ValidationException when someone saved the application in the meantime
     */
    public function update(JobApplication $application, array $details, ?string $jobDescription, int $expectedLockVersion, User $actor): void
    {
        DB::transaction(function () use ($application, $details, $jobDescription, $expectedLockVersion, $actor): void {
            $this->guardLockVersion($application, $expectedLockVersion);

            $application->forceFill([...$details, 'lock_version' => $expectedLockVersion + 1])->save();
            $replaced = $this->storeJobDescription($application, $jobDescription, $actor);

            $this->audit->record('application.updated', $application, ['job_description_replaced' => $replaced], $actor);
        });
    }

    public function changeStage(JobApplication $application, ApplicationStage $stage, User $actor): void
    {
        if ($application->stage === $stage) {
            return;
        }

        DB::transaction(function () use ($application, $stage, $actor): void {
            $from = $application->stage;

            $this->applyStage($application, $stage);
            $application->lock_version++;
            $application->save();

            $this->audit->record('application.stage_changed', $application, ['from' => $from->value, 'to' => $stage->value], $actor);
        });
    }

    public function delete(JobApplication $application, User $actor): void
    {
        DB::transaction(function () use ($application, $actor): void {
            $application->delete();

            $this->audit->record('application.deleted', $application, [], $actor);
        });
    }

    public function restore(JobApplication $application, User $actor): void
    {
        DB::transaction(function () use ($application, $actor): void {
            $application->restore();

            $this->audit->record('application.restored', $application, [], $actor);
        });
    }

    /**
     * The first move to a submitted stage records when the application was sent.
     */
    private function applyStage(JobApplication $application, ApplicationStage $stage): void
    {
        $now = CarbonImmutable::now();

        $application->stage = $stage;
        $application->stage_changed_at = $now;

        if ($stage->isSubmitted() && $application->applied_at === null) {
            $application->applied_at = $now;
        }
    }

    /**
     * Store the pasted job description unless it is empty or unchanged.
     *
     * @return bool whether a new version was stored
     */
    private function storeJobDescription(JobApplication $application, ?string $jobDescription, User $actor): bool
    {
        if ($jobDescription === null || $jobDescription === '') {
            return false;
        }

        $hash = hash('sha256', $jobDescription);

        if ($application->posting?->raw_sha256 === $hash) {
            return false;
        }

        $posting = JobPosting::create([
            'job_application_id' => $application->id,
            'raw_text' => $jobDescription,
            'raw_sha256' => $hash,
            'captured_by' => $actor->id,
        ]);

        $application->setRelation('posting', $posting);

        return true;
    }

    private function guardLockVersion(JobApplication $application, int $expectedLockVersion): void
    {
        $current = JobApplication::query()->whereKey($application->id)->lockForUpdate()->value('lock_version');

        if ($current !== $expectedLockVersion) {
            throw ValidationException::withMessages([
                'lock_version' => __('Someone else changed this application while you were editing. Reload the page to see their version, then apply your change again.'),
            ]);
        }
    }
}
