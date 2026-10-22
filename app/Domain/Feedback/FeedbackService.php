<?php

namespace App\Domain\Feedback;

use App\Domain\Applications\AnswerDraft;
use App\Domain\Applications\AnswerDraftVersion;
use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\WordDiff;
use App\Jobs\AnalyseFeedback;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Captures what a person changed in an AI draft (M10, docs/04 §4.7): on approval, or on
 * "Learn from my edits". The edit is the latest AI version against the current human version;
 * AnalyseFeedback then turns it into proposals.
 */
class FeedbackService
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * The AI version and the human version after it, when the current text is a human edit of
     * an AI draft that has not been learned from yet.
     *
     * @return array{AnswerDraftVersion, AnswerDraftVersion}|null
     */
    public function learnableEdit(AnswerDraft $draft): ?array
    {
        $current = $draft->currentVersion;

        if ($current === null || $current->author_type !== 'user') {
            return null;
        }

        $aiVersion = AnswerDraftVersion::query()
            ->where('answer_draft_id', $draft->id)
            ->where('author_type', 'ai')
            ->where('version_no', '<', $current->version_no)
            ->orderByDesc('version_no')
            ->first();

        if ($aiVersion === null || trim($aiVersion->body) === trim($current->body)) {
            return null;
        }

        $learned = FeedbackEvent::query()
            ->where('from_version_id', $aiVersion->id)
            ->where('to_version_id', $current->id)
            ->exists();

        return $learned ? null : [$aiVersion, $current];
    }

    /**
     * Record the edit and queue its analysis.
     *
     * @param  'approval'|'manual'  $trigger
     * @return FeedbackEvent|null null when there is nothing to learn from
     *
     * @throws ValidationException for `manual` when there is nothing to learn from
     */
    public function capture(AnswerDraft $draft, User $actor, string $trigger): ?FeedbackEvent
    {
        $draft->loadMissing('currentVersion');
        $edit = $this->learnableEdit($draft);
        $diff = $edit === null ? [] : WordDiff::compute($edit[0]->body, $edit[1]->body);

        if ($edit === null || ! WordDiff::hasChanges($diff)) {
            if ($trigger === 'manual') {
                throw ValidationException::withMessages(['feedback' => __('There are no new edits of an AI draft to learn from.')]);
            }

            return null;
        }

        return DB::transaction(function () use ($draft, $actor, $trigger, $edit, $diff): FeedbackEvent {
            $event = new FeedbackEvent;
            $event->forceFill([
                'answer_draft_id' => $draft->id,
                'from_version_id' => $edit[0]->id,
                'to_version_id' => $edit[1]->id,
                // The person whose edit it is decides which rules are personal.
                'user_id' => $edit[1]->author_user_id ?? $actor->id,
                'trigger' => $trigger,
                'diff' => $diff,
                'status' => 'pending_analysis',
            ])->save();

            $this->audit->record('feedback.captured', $draft->question, ['trigger' => $trigger, 'from' => $edit[0]->version_no, 'to' => $edit[1]->version_no], $actor);

            AnalyseFeedback::dispatch($draft->workspace_id, $event->id);

            return $event;
        });
    }
}
