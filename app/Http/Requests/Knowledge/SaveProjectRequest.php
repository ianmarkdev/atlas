<?php

namespace App\Http\Requests\Knowledge;

use App\Concerns\NormalizesPlainText;
use App\Domain\Knowledge\Employment;
use App\Domain\Knowledge\KnowledgeStatus;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create and edit a project. `employment` is the ULID of a non-archived employment in the
 * current workspace, or empty for a standalone project.
 */
class SaveProjectRequest extends FormRequest
{
    use NormalizesPlainText;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'employment' => [
                'nullable',
                'string',
                Rule::exists('employments', 'ulid')
                    ->where('workspace_id', app(TenantContext::class)->workspaceId())
                    ->whereNot('status', KnowledgeStatus::Archived->value),
            ],
            'name' => ['required', 'string', 'max:255'],
            'client_name' => ['nullable', 'string', 'max:255'],
            'role' => ['nullable', 'string', 'max:255'],
            'start_date' => ['nullable', 'date_format:Y-m'],
            'end_date' => ['nullable', 'date_format:Y-m', 'after_or_equal:start_date'],
            'summary' => ['nullable', 'string', 'max:5000'],
            'url' => ['nullable', 'url:http,https', 'max:2048'],
            'lock_version' => [Rule::requiredIf($this->route('project') !== null), 'integer'],
            // A VA's note to the candidate when suggesting a change to a verified item.
            'rationale' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Validated model attributes, with the employment ULID resolved and months converted to dates.
     *
     * @return array<string, mixed>
     */
    public function projectAttributes(): array
    {
        $validated = $this->safe()->except(['lock_version', 'employment', 'rationale']);
        $employmentUlid = $this->validated('employment');

        return [
            ...$validated,
            'employment_id' => $employmentUlid === null ? null : Employment::query()->where('ulid', $employmentUlid)->value('id'),
            'start_date' => isset($validated['start_date']) ? $validated['start_date'].'-01' : null,
            'end_date' => isset($validated['end_date']) ? $validated['end_date'].'-01' : null,
        ];
    }

    protected function plainTextFields(): array
    {
        return [
            'name' => false,
            'client_name' => false,
            'role' => false,
            'summary' => true,
            'rationale' => true,
            'url' => false,
        ];
    }
}
