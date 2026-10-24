<?php

namespace App\Domain\Applications;

use App\Domain\Ai\GenerationRun;
use App\Domain\Audit\AuditLogger;
use App\Jobs\CheckAnswerClaims;
use App\Jobs\GenerateAnswerDraft;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * AI-drafted answers and cover letters (M8). A request queues GenerateAnswerDraft; its result is
 * stored as a new version of the answer (author `ai`) — a draft the candidate edits and approves
 * like any other, never sent anywhere by itself.
 *
 * @phpstan-import-type Retrieval from EvidencePackBuilder
 *
 * @phpstan-type GenerationInputs array{writing_rule_ids: list<int>, writing_rules: list<array{id: int, line: string}>, retrieval: Retrieval|null, job_posting_id: int|null, job_analysis_id: int|null}
 */
class AiAnswerService
{
    public function __construct(private AuditLogger $audit, private ParagraphRedraft $paragraphs) {}

    /**
     * @param  string|null  $instruction  how this draft should differ ("shorter", "more formal")
     * @param  int|null  $paragraph  redraft only this paragraph (0-based) of the current text
     * @param  bool  $bulk  one of many drafts asked for at once, queued behind single requests
     *
     * @throws ValidationException when the answer is approved, a draft is already being written, or the paragraph does not exist
     */
    public function request(ApplicationQuestion $question, User $actor, ?string $instruction = null, ?int $paragraph = null, bool $bulk = false): AnswerDraft
    {
        return DB::transaction(function () use ($question, $actor, $instruction, $paragraph, $bulk): AnswerDraft {
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

            if ($paragraph !== null && ! array_key_exists($paragraph, ParagraphRedraft::paragraphs($draft->exists ? $draft->body() : ''))) {
                throw ValidationException::withMessages(['paragraph' => __('That paragraph is not in the saved answer. Save your changes, then try again.')]);
            }

            $draft->forceFill([
                'generation_status' => 'queued',
                'generation_error' => null,
                'generation_requested_at' => now(),
                'generation_requested_by' => $actor->id,
                'generation_instruction' => $instruction,
                'generation_paragraph' => $paragraph,
                'generation_base_version_id' => $paragraph === null ? null : $draft->current_version_id,
            ])->save();

            $this->audit->record('answer_draft.generation_requested', $question, ['instruction' => $instruction, 'paragraph' => $paragraph], $actor);

            GenerateAnswerDraft::dispatch($question->workspace_id, $question->id, $bulk);

            return $draft;
        });
    }

    /**
     * Queue AI drafts for every question of the application that has no answer yet, in form
     * order with the cover letter last (it is written from the resume and the other answers'
     * evidence). Questions with any text, an approved answer, or a draft on the way are skipped.
     *
     * @return list<ApplicationQuestion> the questions queued
     */
    public function requestUnanswered(JobApplication $application, User $actor): array
    {
        $questions = ApplicationQuestion::query()
            ->where('job_application_id', $application->id)
            ->with('answer.currentVersion')
            ->orderBy('position')
            ->get()
            ->filter(fn (ApplicationQuestion $question): bool => $question->answer === null
                || (! $question->answer->isApproved() && ! $question->answer->isGenerating() && trim($question->answer->body()) === ''))
            ->sortBy(fn (ApplicationQuestion $question): int => $question->isCoverLetter() ? 1 : 0)
            ->values();

        return DB::transaction(fn (): array => $questions
            ->each(fn (ApplicationQuestion $question) => $this->request($question, $actor, bulk: true))
            ->all());
    }

    /**
     * Save the generated text as the answer's new current version.
     *
     * @param  array<string, array{ref: ?string, status: string, text: string}>  $evidencePack
     * @param  list<array<string, mixed>>|null  $grounding  the checked claims; null when CheckAnswerClaims labels them afterwards
     * @param  list<array{question: string, why: string}>  $clarifications
     * @param  GenerationInputs|array{}  $inputs  what else the writer was given
     */
    public function storeDraft(AnswerDraft $draft, string $body, GenerationRun $run, array $evidencePack, ?array $grounding, array $clarifications, ?User $requester, ?string $instruction = null, ?int $resumeVersionId = null, array $inputs = []): void
    {
        DB::transaction(function () use ($draft, $body, $run, $evidencePack, $grounding, $clarifications, $requester, $instruction, $resumeVersionId, $inputs): void {
            $draft = AnswerDraft::query()->whereKey($draft->id)->lockForUpdate()->firstOrFail();

            if ($draft->isApproved()) {
                // Approved while the draft was being written: keep the approved text untouched.
                $draft->forceFill(['generation_status' => 'failed', 'generation_error' => __('The answer was approved while the AI draft was being written; the draft was discarded.')])->save();

                return;
            }

            $version = $this->appendAiVersion($draft, [
                'body' => $body,
                'author_user_id' => $requester?->id,
                'generation_run_id' => $run->id,
                'instruction' => $instruction,
                'resume_version_id' => $resumeVersionId,
                'evidence_pack' => $evidencePack,
                ...$inputs,
                'grounding' => $grounding,
                'clarification_questions' => $clarifications,
            ]);

            if ($grounding === null) {
                // The text is there to read and edit; approving waits for the labels.
                $draft->forceFill(['generation_status' => 'checking'])->save();
                CheckAnswerClaims::dispatch($draft->workspace_id, $version->id);
            }
        });
    }

    /**
     * Store the checked claims of an AI version (CheckAnswerClaims). The draft leaves `checking`
     * when that version is still the current one.
     *
     * @param  list<array<string, mixed>>  $grounding
     */
    public function storeClaims(AnswerDraftVersion $version, array $grounding): void
    {
        DB::transaction(function () use ($version, $grounding): void {
            $draft = AnswerDraft::query()->whereKey($version->answer_draft_id)->lockForUpdate()->firstOrFail();
            $version->forceFill(['grounding' => $grounding])->save();

            if ($draft->generation_status === 'checking') {
                $draft->forceFill(['generation_status' => null, 'generation_error' => null])->save();
            }
        });
    }

    /**
     * The claim check failed: the draft stays unlabelled, so it can only be approved after
     * someone checks it as a whole ("Keep all as worded").
     */
    public function failClaimCheck(AnswerDraftVersion $version, string $message): void
    {
        $draft = AnswerDraft::query()->whereKey($version->answer_draft_id)->first();

        if ($draft?->generation_status === 'checking') {
            $this->fail($draft, __('The AI could not label this draft\'s claims (:reason). Read it carefully, then use "Keep all as worded" under AI check, or redraft.', ['reason' => $message]));
        }
    }

    /**
     * Put a redrafted paragraph back into the version it was cut from, as the answer's new
     * current version. What was checked in the other paragraphs stays checked (ParagraphRedraft).
     * Discarded when the answer was approved or saved while the paragraph was being written.
     *
     * @param  array<string, array{ref: ?string, status: string, text: string}>  $evidencePack
     * @param  list<array<string, mixed>>  $grounding  the new paragraph's claims, already checked
     * @param  list<array{question: string, why: string}>  $clarifications
     * @param  GenerationInputs|array{}  $inputs  what else the writer was given
     */
    public function storeParagraph(AnswerDraft $draft, int $baseVersionId, int $paragraph, string $text, GenerationRun $run, array $evidencePack, array $grounding, array $clarifications, ?User $requester, ?string $instruction = null, array $inputs = []): void
    {
        DB::transaction(function () use ($draft, $baseVersionId, $paragraph, $text, $run, $evidencePack, $grounding, $clarifications, $requester, $instruction, $inputs): void {
            $draft = AnswerDraft::query()->whereKey($draft->id)->lockForUpdate()->firstOrFail();
            $base = AnswerDraftVersion::query()->with('reviews')->find($baseVersionId);

            if ($draft->isApproved() || $base === null || $draft->current_version_id !== $base->id || ! array_key_exists($paragraph, ParagraphRedraft::paragraphs($base->body))) {
                $draft->forceFill(['generation_status' => 'failed', 'generation_error' => __('The answer was saved or approved while the paragraph was being redrafted, so nothing was replaced. Try again.')])->save();

                return;
            }

            $body = ParagraphRedraft::splice($base->body, $paragraph, $text);
            $merged = $this->paragraphs->merge($draft, $base, $body, $paragraph, $grounding, $clarifications);

            $version = $this->appendAiVersion($draft, [
                'body' => $body,
                'author_user_id' => $requester?->id,
                'generation_run_id' => $run->id,
                'instruction' => $instruction,
                'paragraph' => $paragraph,
                'resume_version_id' => $base->resume_version_id,
                'evidence_pack' => $evidencePack,
                ...$inputs,
                'grounding' => $merged['grounding'],
                'clarification_questions' => $merged['questions'],
            ]);

            foreach ($merged['reviews'] as $review) {
                (new AnswerClaimReview)->forceFill([...$review, 'answer_draft_version_id' => $version->id])->save();
            }
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function appendAiVersion(AnswerDraft $draft, array $attributes): AnswerDraftVersion
    {
        $version = AnswerDraftVersion::create([
            'answer_draft_id' => $draft->id,
            'version_no' => (int) AnswerDraftVersion::query()->where('answer_draft_id', $draft->id)->max('version_no') + 1,
            'author_type' => 'ai',
            'created_via' => 'ai',
            ...$attributes,
        ]);

        // Bumping the lock makes an editor that was open before the draft arrived reload first.
        $draft->forceFill([
            'current_version_id' => $version->id,
            'lock_version' => $draft->lock_version + 1,
            'generation_status' => null,
            'generation_error' => null,
            'generation_instruction' => null,
            'generation_paragraph' => null,
            'generation_base_version_id' => null,
        ])->save();

        return $version;
    }

    /**
     * Record that someone checked the claims of the current AI draft (accepting the hedged
     * wording), which lets it be approved.
     *
     * @throws ValidationException when the current version is not an AI draft
     */
    public function markClaimsReviewed(ApplicationQuestion $question, User $actor): void
    {
        $draft = $question->answer?->loadMissing('currentVersion');
        $version = $draft?->currentVersion;

        if ($draft === null || $version === null || $version->author_type !== 'ai') {
            throw ValidationException::withMessages(['body' => __('Only an AI draft has claims to check.')]);
        }

        $draft->forceFill(['claims_reviewed_version_id' => $version->id])->save();

        $this->audit->record('answer_draft.claims_reviewed', $question, ['version_no' => $version->version_no], $actor);
    }

    public function fail(AnswerDraft $draft, string $message): void
    {
        $draft->forceFill(['generation_status' => 'failed', 'generation_error' => mb_strimwidth($message, 0, 1000, '…')])->save();
    }
}
