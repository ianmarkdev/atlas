<?php

namespace App\Domain\Applications;

use App\Domain\Ai\GenerationRun;
use App\Domain\Audit\AuditLogger;
use App\Jobs\GenerateAnswerDraft;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * AI-drafted answers and cover letters (M8). A request queues GenerateAnswerDraft; its result is
 * stored as a new version of the answer (author `ai`) — a draft the candidate edits and approves
 * like any other, never sent anywhere by itself.
 */
class AiAnswerService
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @throws ValidationException when the answer is approved or a draft is already being written
     */
    public function request(ApplicationQuestion $question, User $actor): AnswerDraft
    {
        return DB::transaction(function () use ($question, $actor): AnswerDraft {
            $draft = AnswerDraft::query()->where('application_question_id', $question->id)->lockForUpdate()->first();

            if ($draft === null) {
                $draft = new AnswerDraft;
                $draft->forceFill(['application_question_id' => $question->id, 'status' => 'draft', 'lock_version' => 0]);
            }

            if ($draft->isApproved()) {
                throw ValidationException::withMessages(['body' => __('This answer is approved. Reopen it before drafting a new version.')]);
            }

            if ($draft->isGenerating()) {
                throw ValidationException::withMessages(['body' => __('A draft is already being written.')]);
            }

            $draft->forceFill([
                'generation_status' => 'queued',
                'generation_error' => null,
                'generation_requested_at' => now(),
                'generation_requested_by' => $actor->id,
            ])->save();

            $this->audit->record('answer_draft.generation_requested', $question, [], $actor);

            GenerateAnswerDraft::dispatch($question->workspace_id, $question->id);

            return $draft;
        });
    }

    /**
     * Save the generated text as the answer's new current version.
     *
     * @param  array<string, array{ref: ?string, status: string, text: string}>  $evidencePack
     * @param  list<array<string, mixed>>  $grounding
     * @param  list<array{question: string, why: string}>  $clarifications
     */
    public function storeDraft(AnswerDraft $draft, string $body, GenerationRun $run, array $evidencePack, array $grounding, array $clarifications, ?User $requester): void
    {
        DB::transaction(function () use ($draft, $body, $run, $evidencePack, $grounding, $clarifications, $requester): void {
            $draft = AnswerDraft::query()->whereKey($draft->id)->lockForUpdate()->firstOrFail();

            if ($draft->isApproved()) {
                // Approved while the draft was being written: keep the approved text untouched.
                $draft->forceFill(['generation_status' => 'failed', 'generation_error' => __('The answer was approved while the AI draft was being written; the draft was discarded.')])->save();

                return;
            }

            $version = AnswerDraftVersion::create([
                'answer_draft_id' => $draft->id,
                'version_no' => (int) AnswerDraftVersion::query()->where('answer_draft_id', $draft->id)->max('version_no') + 1,
                'body' => $body,
                'author_type' => 'ai',
                'author_user_id' => $requester?->id,
                'created_via' => 'ai',
                'generation_run_id' => $run->id,
                'evidence_pack' => $evidencePack,
                'grounding' => $grounding,
                'clarification_questions' => $clarifications,
            ]);

            // Bumping the lock makes an editor that was open before the draft arrived reload first.
            $draft->forceFill([
                'current_version_id' => $version->id,
                'lock_version' => $draft->lock_version + 1,
                'generation_status' => null,
                'generation_error' => null,
            ])->save();
        });
    }

    public function fail(AnswerDraft $draft, string $message): void
    {
        $draft->forceFill(['generation_status' => 'failed', 'generation_error' => mb_strimwidth($message, 0, 1000, '…')])->save();
    }
}
