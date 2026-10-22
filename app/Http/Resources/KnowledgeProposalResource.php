<?php

namespace App\Http\Resources;

use App\Domain\Knowledge\AnswerCategory;
use App\Domain\Knowledge\AnswerLibraryEntry;
use App\Domain\Knowledge\Claim;
use App\Domain\Knowledge\ClaimRevision;
use App\Domain\Knowledge\ClaimSubject;
use App\Domain\Knowledge\Credential;
use App\Domain\Knowledge\Employment;
use App\Domain\Knowledge\KnowledgeProposal;
use App\Domain\Knowledge\Project;
use App\Domain\Knowledge\ProposalStatus;
use App\Domain\Knowledge\WritingPreferenceKind;
use App\Domain\Resumes\ResumeVariant;
use App\Domain\Shared\WordDiff;
use BackedEnum;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A suggestion as the reviewer sees it: which item, what changes (old → new, with a word diff
 * for text), who or what suggested it and why, and what may be edited before approving.
 *
 * @mixin KnowledgeProposal
 */
class KnowledgeProposalResource extends JsonResource
{
    /**
     * Field labels per target type, in display order. Only these fields are compared.
     *
     * @var array<string, array<string, string>>
     */
    private const FIELDS = [
        'claim' => ['statement' => 'Bullet point', 'kind' => 'Kind', 'subject' => 'Where'],
        'employment' => [
            'employer_name' => 'Employer', 'title' => 'Official job title', 'employment_type' => 'Type',
            'location' => 'Location', 'start_date' => 'Start', 'end_date' => 'End', 'summary' => 'Short description',
        ],
        'project' => [
            'name' => 'Project name', 'employment_id' => 'Experience', 'role' => 'Your role', 'client_name' => 'Client',
            'start_date' => 'Start', 'end_date' => 'End', 'url' => 'Link', 'summary' => 'Short description',
        ],
        'answer_library_entry' => ['question' => 'Question', 'category' => 'Category', 'body' => 'Answer'],
        'credential' => [
            'type' => 'Type', 'name' => 'Name', 'institution' => 'School / issuer', 'field_of_study' => 'Field of study',
            'start_date' => 'Start', 'end_date' => 'End / awarded', 'expires_on' => 'Expires',
            'credential_url' => 'Link', 'summary' => 'Short description',
        ],
        'writing_preference' => ['rule' => 'Rule', 'kind' => 'Kind', 'scope' => 'Applies to'],
        'resume_variant' => ['title' => 'Title', 'summary' => 'Summary'],
    ];

    /** Fields shown with a word diff. */
    private const TEXT_FIELDS = ['statement', 'body', 'summary', 'rule'];

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $target = $this->isCreation() ? null : $this->target;
        $isMissing = ! $this->isCreation() && $target === null;
        $isPending = $this->status === ProposalStatus::Pending;

        return [
            'ulid' => $this->ulid,
            'type' => $this->proposal_type,
            'status' => $this->status->value,
            'origin' => $this->origin,
            // An AI achievement suggestion: the question to answer and the draft to complete (M9).
            'suggestion' => $this->origin !== 'achievement_suggestion' || ! $target instanceof Claim ? null : [
                'question' => $this->rationale,
                'current' => $target->statement(),
                'draft' => (string) ($this->payload['statement'] ?? ''),
            ],
            'learned_from' => $this->learnedFrom(),
            'target_type' => $this->target_type,
            'target_label' => $isMissing ? __('Deleted item') : $this->targetLabel($target),
            'changes' => $isMissing ? [] : $this->changes($target),
            'editable' => $isPending ? $this->editable() : null,
            'personal' => $this->reviewer_user_id !== null,
            'rationale' => $this->rationale,
            'proposed_by' => $this->proposer?->name ?? ($this->proposed_by_run_id !== null ? __('AI') : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'decided_by' => $this->decider?->name,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'decision_note' => $this->decision_note,
            'can' => [
                'decide' => $isPending && ($request->user()?->can('decide', $this->resource) ?? false),
            ],
        ];
    }

    /**
     * @return list<array{label: string, from: string|null, to: string|null, base: string|null, diff: list<array{op: string, text: string}>|null}>
     */
    private function changes(Claim|Employment|Project|Credential|AnswerLibraryEntry|ResumeVariant|null $target): array
    {
        $changes = [];

        foreach (self::FIELDS[$this->target_type] ?? [] as $field => $label) {
            if (! array_key_exists($field, $this->payload)) {
                continue;
            }

            $from = $target === null ? null : $this->display($field, $this->currentValue($target, $field));
            $to = $this->display($field, $this->payload[$field]);

            if ($from === $to) {
                continue;
            }

            // Changed since the suggestion was made: also show the wording it was based on.
            $base = $field === 'statement' && $target instanceof Claim && $target->current_revision_id !== $this->base_version
                ? ClaimRevision::query()->whereKey($this->base_version)->value('statement')
                : null;

            $changes[] = [
                'label' => __($label),
                'from' => $from,
                'to' => $to,
                'base' => $base,
                'diff' => in_array($field, self::TEXT_FIELDS, true) && $from !== null && $to !== null ? WordDiff::compute($from, $to) : null,
            ];
        }

        return $changes;
    }

    private function currentValue(Claim|Employment|Project|Credential|AnswerLibraryEntry|ResumeVariant $target, string $field): mixed
    {
        return match (true) {
            $target instanceof Claim && $field === 'statement' => $target->statement(),
            $target instanceof AnswerLibraryEntry && $field === 'body' => $target->body(),
            $target instanceof ResumeVariant => $target->document()[$field] ?? null,
            default => $target->getAttribute($field),
        };
    }

    /**
     * What the reviewer may reword before approving, with the suggested value.
     *
     * @return array{field: string, value: string, subject: string|null}|null
     */
    private function editable(): ?array
    {
        $field = match ($this->proposal_type) {
            'revise_claim', 'create_claim' => 'statement',
            'revise_library_answer', 'create_library_answer' => 'body',
            'create_writing_preference' => 'rule',
            default => null,
        };

        return $field === null ? null : [
            'field' => $field,
            'value' => (string) ($this->payload[$field] ?? ''),
            'subject' => $this->proposal_type === 'create_claim' ? (string) ($this->payload['subject'] ?? 'general') : null,
        ];
    }

    /**
     * For a suggestion learned from an edited answer: which answer.
     *
     * @return array{application: string, application_ulid: string, question: string}|null
     */
    private function learnedFrom(): ?array
    {
        $question = $this->origin === 'feedback' ? $this->feedbackEvent?->answerDraft?->question : null;

        if ($question === null || $question->application === null) {
            return null;
        }

        return [
            'application' => __(':role at :company', ['role' => $question->application->role_title, 'company' => $question->application->companyLabel()]),
            'application_ulid' => $question->application->ulid,
            'question' => $question->isCoverLetter() ? __('Cover letter') : $question->prompt_text,
        ];
    }

    private function display(string $field, mixed $value): ?string
    {
        return match (true) {
            $value === null || $value === '' => null,
            $field === 'employment_id' => Employment::query()->whereKey($value)->get(['title', 'employer_name'])
                ->map(fn (Employment $e): string => "{$e->title} — {$e->employer_name}")->first(),
            $field === 'subject' => $this->subjectLabel((string) $value),
            $field === 'scope' => $value === 'user' ? __('Only your drafts') : __('Every draft'),
            $field === 'kind' && $this->target_type === 'writing_preference' => WritingPreferenceKind::tryFrom((string) $value)?->label(),
            $field === 'category' => AnswerCategory::tryFrom((string) $value)?->label() ?? (string) $value,
            $value instanceof CarbonInterface => $value->format('Y-m'),
            $value instanceof BackedEnum => (string) $value->value,
            is_string($value) && preg_match('/^\d{4}-\d{2}-01$/', $value) === 1 => substr($value, 0, 7),
            default => (string) $value,
        };
    }

    private function subjectLabel(string $key): ?string
    {
        $subject = ClaimSubject::resolve($key);

        return match (true) {
            $subject instanceof Employment => "{$subject->title} — {$subject->employer_name}",
            $subject instanceof Project => __('Project: :name', ['name' => $subject->name]),
            default => __('General'),
        };
    }

    private function targetLabel(Claim|Employment|Project|Credential|AnswerLibraryEntry|ResumeVariant|null $target): string
    {
        return match (true) {
            $this->proposal_type === 'create_claim' => __('New bullet point'),
            $this->proposal_type === 'create_library_answer' => __('New library answer: :question', ['question' => (string) ($this->payload['question'] ?? '')]),
            $this->proposal_type === 'create_writing_preference' => $this->reviewer_user_id !== null ? __('New personal writing rule') : __('New writing rule'),
            $target instanceof Claim => __('Bullet point'),
            $target instanceof Employment => __('Experience: :title — :employer', ['title' => $target->title, 'employer' => $target->employer_name]),
            $target instanceof Project => __('Project: :name', ['name' => $target->name]),
            $target instanceof AnswerLibraryEntry => __('Answer: :question', ['question' => $target->question]),
            $target instanceof ResumeVariant => __('Resume: :name', ['name' => $target->name]),
            $target instanceof Credential => __(':name — :institution', ['name' => $target->name, 'institution' => $target->institution]),
            default => __('Deleted item'),
        };
    }
}
