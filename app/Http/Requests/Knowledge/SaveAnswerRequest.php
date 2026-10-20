<?php

namespace App\Http\Requests\Knowledge;

use App\Concerns\NormalizesPlainText;
use App\Domain\Knowledge\AnswerCategory;
use App\Domain\Knowledge\ClaimStatus;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create and edit an answer-library entry. `claims` (optional) are ULIDs of live bullet points
 * in this workspace that the answer relies on.
 */
class SaveAnswerRequest extends FormRequest
{
    use NormalizesPlainText;

    public const MAX_BODY_LENGTH = 5000;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'question' => ['required', 'string', 'max:500'],
            'body' => ['required', 'string', 'max:'.self::MAX_BODY_LENGTH],
            'category' => ['required', Rule::enum(AnswerCategory::class)],
            'claims' => ['sometimes', 'array', 'max:20'],
            'claims.*' => [
                'string',
                'distinct',
                Rule::exists('claims', 'ulid')
                    ->where('workspace_id', app(TenantContext::class)->workspaceId())
                    ->whereIn('status', array_map(fn (ClaimStatus $status): string => $status->value, ClaimStatus::live())),
            ],
            'change_reason' => ['nullable', 'string', 'max:255'],
            'lock_version' => [Rule::requiredIf($this->route('answer') !== null), 'integer'],
            // A VA's note to the candidate when suggesting a change to an approved answer.
            'rationale' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return list<string>
     */
    public function claimUlids(): array
    {
        return array_values(array_filter((array) $this->validated('claims', []), is_string(...)));
    }

    /**
     * The values a VA's suggestion carries (ProposalService applies them on approval).
     *
     * @return array<string, mixed>
     */
    public function proposalPayload(): array
    {
        return [
            'question' => $this->validated('question'),
            'body' => $this->validated('body'),
            'category' => $this->validated('category'),
            'claim_ulids' => $this->claimUlids(),
        ];
    }

    protected function plainTextFields(): array
    {
        return ['question' => false, 'body' => true, 'change_reason' => false, 'rationale' => true];
    }
}
