<?php

namespace App\Domain\Applications;

use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The preparation workflow between VAs and the candidate (D10): assign, work, submit for review,
 * request changes or approve. Every step is audited (the application's timeline).
 */
class ApplicationReviewService
{
    /**
     * Allowed moves: action => statuses it can start from.
     *
     * @var array<string, list<ReviewStatus>>
     */
    private const FROM = [
        'start' => [ReviewStatus::NotStarted, ReviewStatus::ChangesRequested],
        'submit' => [ReviewStatus::NotStarted, ReviewStatus::InProgress, ReviewStatus::ChangesRequested],
        'request_changes' => [ReviewStatus::InReview],
        'approve' => [ReviewStatus::NotStarted, ReviewStatus::InProgress, ReviewStatus::InReview, ReviewStatus::ChangesRequested],
        'reopen' => [ReviewStatus::Approved],
    ];

    public function __construct(private AuditLogger $audit) {}

    /**
     * @throws ValidationException when the assignee is not an active member of the workspace
     */
    public function assign(JobApplication $application, ?User $assignee, User $actor): void
    {
        if ($assignee !== null && (! $assignee->is_active || $assignee->membership?->workspace_id !== $application->workspace_id)) {
            throw ValidationException::withMessages(['assignee' => __('Choose an active member of your team.')]);
        }

        DB::transaction(function () use ($application, $assignee, $actor): void {
            $from = $application->assigned_user_id;
            $application->forceFill(['assigned_user_id' => $assignee?->id])->save();

            $this->audit->record('application.assigned', $application, ['from' => $from, 'to' => $assignee?->id], $actor);
        });
    }

    public function start(JobApplication $application, User $actor): void
    {
        $this->move($application, 'start', ReviewStatus::InProgress, null, $actor);
    }

    public function submit(JobApplication $application, ?string $note, User $actor): void
    {
        $this->move($application, 'submit', ReviewStatus::InReview, $note, $actor);
    }

    public function requestChanges(JobApplication $application, string $note, User $actor): void
    {
        $this->move($application, 'request_changes', ReviewStatus::ChangesRequested, $note, $actor);
    }

    /**
     * The candidate's sign-off: everything prepared for the application must be approved first.
     *
     * @throws ValidationException listing what still needs approval
     */
    public function approve(JobApplication $application, User $actor): void
    {
        $missing = $this->notYetApproved($application);

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'review' => __('Approve these first: :items', ['items' => implode('; ', $missing)]),
            ]);
        }

        $this->move($application, 'approve', ReviewStatus::Approved, null, $actor);
    }

    public function reopen(JobApplication $application, User $actor): void
    {
        $this->move($application, 'reopen', ReviewStatus::InProgress, null, $actor);
    }

    /**
     * Labels of the tailored resume and answers that are not approved yet.
     *
     * @return list<string>
     */
    public function notYetApproved(JobApplication $application): array
    {
        $application->loadMissing(['resume', 'questions.answer']);
        $missing = [];

        if ($application->resume !== null && ! $application->resume->isApproved()) {
            $missing[] = __('the tailored resume');
        }

        foreach ($application->questions as $question) {
            if (! ($question->answer?->isApproved() ?? false)) {
                $missing[] = $question->isCoverLetter()
                    ? __('the cover letter')
                    : '“'.mb_strimwidth($question->prompt_text, 0, 60, '…').'”';
            }
        }

        return $missing;
    }

    /**
     * Whether `$user` may be given applications to prepare: active VAs and the candidate.
     */
    public static function canBeAssigned(User $user): bool
    {
        return $user->is_active && in_array($user->role(), [Role::Va, Role::Candidate], true);
    }

    private function move(JobApplication $application, string $action, ReviewStatus $to, ?string $note, User $actor): void
    {
        DB::transaction(function () use ($application, $action, $to, $note, $actor): void {
            $current = JobApplication::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();
            $from = $current->review_status;

            if (! in_array($from, self::FROM[$action], true)) {
                throw ValidationException::withMessages([
                    'review' => __('This application is :status; that step is not possible now. Reload the page.', ['status' => mb_strtolower($from->label())]),
                ]);
            }

            $application->forceFill([
                'review_status' => $to,
                'review_status_changed_at' => now(),
                // Keep the note that explains the current state; approving or restarting clears it.
                'review_note' => $note ?? ($to === ReviewStatus::InProgress && $from === ReviewStatus::ChangesRequested ? $current->review_note : null),
            ])->save();

            $this->audit->record("application.review_{$action}", $application, array_filter([
                'from' => $from->value,
                'to' => $to->value,
                'note' => $note,
            ], fn (mixed $value): bool => $value !== null), $actor);
        });
    }
}
