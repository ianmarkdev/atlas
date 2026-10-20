<?php

namespace App\Http\Resources;

use App\Domain\Knowledge\AnswerLibraryEntry;
use App\Domain\Knowledge\Claim;
use App\Domain\Knowledge\Credential;
use App\Domain\Knowledge\Employment;
use App\Domain\Knowledge\KnowledgeProposal;
use App\Domain\Knowledge\Project;
use App\Domain\Knowledge\ProposalStatus;
use BackedEnum;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A suggestion as the candidate reviews it: which item, what changes (old → new), who and why.
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
        'claim' => ['statement' => 'Bullet point', 'kind' => 'Kind'],
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
    ];

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $target = $this->target;
        $isPending = $this->status === ProposalStatus::Pending;

        return [
            'ulid' => $this->ulid,
            'status' => $this->status->value,
            'target_type' => $this->target_type,
            'target_label' => $target === null ? __('Deleted item') : $this->targetLabel($target),
            'changes' => $target === null ? [] : $this->changes($target),
            'rationale' => $this->rationale,
            'proposed_by' => $this->proposer?->name,
            'created_at' => $this->created_at?->toIso8601String(),
            'decided_by' => $this->decider?->name,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'decision_note' => $this->decision_note,
            'can' => [
                'decide' => $isPending && ($request->user()?->can('approve-proposals') ?? false),
            ],
        ];
    }

    /**
     * @return list<array{label: string, from: string|null, to: string|null}>
     */
    private function changes(Claim|Employment|Project|Credential|AnswerLibraryEntry $target): array
    {
        $changes = [];

        foreach (self::FIELDS[$this->target_type] ?? [] as $field => $label) {
            if (! array_key_exists($field, $this->payload)) {
                continue;
            }

            $current = match (true) {
                $field === 'statement' && $target instanceof Claim => $target->statement(),
                $field === 'body' && $target instanceof AnswerLibraryEntry => $target->body(),
                default => $target->getAttribute($field),
            };
            $from = $this->display($field, $current);
            $to = $this->display($field, $this->payload[$field]);

            if ($from !== $to) {
                $changes[] = ['label' => __($label), 'from' => $from, 'to' => $to];
            }
        }

        return $changes;
    }

    private function display(string $field, mixed $value): ?string
    {
        return match (true) {
            $value === null || $value === '' => null,
            $field === 'employment_id' => Employment::query()->whereKey($value)->get(['title', 'employer_name'])
                ->map(fn (Employment $e): string => "{$e->title} — {$e->employer_name}")->first(),
            $value instanceof CarbonInterface => $value->format('Y-m'),
            $value instanceof BackedEnum => (string) $value->value,
            is_string($value) && preg_match('/^\d{4}-\d{2}-01$/', $value) === 1 => substr($value, 0, 7),
            default => (string) $value,
        };
    }

    private function targetLabel(Claim|Employment|Project|Credential|AnswerLibraryEntry $target): string
    {
        return match (true) {
            $target instanceof Claim => __('Bullet point'),
            $target instanceof Employment => __('Experience: :title — :employer', ['title' => $target->title, 'employer' => $target->employer_name]),
            $target instanceof Project => __('Project: :name', ['name' => $target->name]),
            $target instanceof AnswerLibraryEntry => __('Answer: :question', ['question' => $target->question]),
            default => __(':name — :institution', ['name' => $target->name, 'institution' => $target->institution]),
        };
    }
}
