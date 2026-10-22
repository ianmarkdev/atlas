<?php

namespace App\Domain\Applications;

use App\Domain\Audit\AuditLogger;
use App\Domain\Knowledge\ClaimKind;
use App\Domain\Knowledge\ClaimService;
use App\Domain\Knowledge\Employment;
use App\Domain\Knowledge\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turns analysis gaps into questions and answers into evidence. No AI: the question is a fixed
 * template, and an answer is saved as a bullet point through ClaimService (verified for the
 * candidate, proposed for a VA, with the usual duplicate checks).
 */
class ClarificationService
{
    public function __construct(private AuditLogger $audit, private ClaimService $claims) {}

    /**
     * After (re-)matching: open a question for each must-have with no evidence, and resolve open
     * questions whose requirement now has strong evidence.
     */
    public function syncFromAnalysis(JobAnalysis $analysis): void
    {
        $requirements = (array) ($analysis->matches['requirements'] ?? []);

        DB::transaction(function () use ($analysis, $requirements): void {
            foreach ($requirements as $row) {
                if (! is_array($row) || ($row['kind'] ?? null) !== 'must') {
                    continue;
                }

                $requirement = trim((string) ($row['requirement'] ?? ''));
                $hash = ClarificationRequest::hashOf($requirement);
                $open = ClarificationRequest::query()
                    ->where('job_application_id', $analysis->job_application_id)
                    ->where('requirement_hash', $hash)
                    ->where('status', 'open')
                    ->first();

                if (($row['coverage'] ?? null) === 'strong') {
                    $open?->forceFill(['status' => 'resolved'])->save();

                    continue;
                }

                if (($row['coverage'] ?? null) === 'gap' && $open === null && ! $this->alreadyHandled($analysis->job_application_id, $hash)) {
                    $request = new ClarificationRequest;
                    $request->forceFill([
                        'job_application_id' => $analysis->job_application_id,
                        'job_analysis_id' => $analysis->id,
                        'requirement' => $requirement,
                        'requirement_hash' => $hash,
                        'question_text' => __('The job asks for “:requirement”. Have you done this? If so, describe it as a bullet point: what you did, where, and the result.', ['requirement' => $requirement]),
                        'status' => 'open',
                    ])->save();
                }
            }
        });
    }

    /**
     * Save the answer as a bullet point about `$subject` (null = general) and close the question.
     *
     * @throws ValidationException when the question is not open, or the bullet point is a duplicate
     */
    public function answer(ClarificationRequest $request, string $answer, Employment|Project|null $subject, User $actor): void
    {
        if ($request->status !== 'open') {
            throw ValidationException::withMessages(['answer' => __('This question has already been dealt with.')]);
        }

        DB::transaction(function () use ($request, $answer, $subject, $actor): void {
            $claim = $this->claims->create($answer, ClaimKind::Achievement, $subject, $actor);

            $request->forceFill([
                'status' => 'answered',
                'answer_text' => $answer,
                'converted_claim_id' => $claim->id,
                'answered_by' => $actor->id,
                'answered_at' => now(),
            ])->save();

            $this->audit->record('clarification.answered', $request->application, ['requirement' => mb_strimwidth($request->requirement, 0, 120, '…')], $actor);
        });
    }

    public function dismiss(ClarificationRequest $request, User $actor): void
    {
        if ($request->status !== 'open') {
            return;
        }

        $request->forceFill(['status' => 'dismissed', 'answered_by' => $actor->id, 'answered_at' => now()])->save();
    }

    /**
     * A requirement that was answered or dismissed before is not asked again on re-analysis.
     */
    private function alreadyHandled(int $applicationId, string $hash): bool
    {
        return ClarificationRequest::query()
            ->where('job_application_id', $applicationId)
            ->where('requirement_hash', $hash)
            ->whereIn('status', ['answered', 'dismissed'])
            ->exists();
    }
}
