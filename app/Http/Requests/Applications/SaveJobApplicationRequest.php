<?php

namespace App\Http\Requests\Applications;

use App\Concerns\NormalizesPlainText;
use App\Domain\Applications\ApplicationStage;
use App\Domain\Knowledge\EmploymentType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Add or edit an application. The job description is pasted as plain text (D8) and kept
 * verbatim after normalisation.
 */
class SaveJobApplicationRequest extends FormRequest
{
    use NormalizesPlainText;

    public const MAX_JOB_DESCRIPTION_LENGTH = 50000;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $isCreating = $this->route('application') === null;

        return [
            'company_name' => ['required', 'string', 'max:200'],
            'role_title' => ['required', 'string', 'max:200'],
            'source_url' => ['nullable', 'url:http,https', 'max:2000'],
            'location' => ['nullable', 'string', 'max:200'],
            'employment_type' => ['nullable', Rule::enum(EmploymentType::class)],
            'salary_text' => ['nullable', 'string', 'max:200'],
            'deadline_on' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'job_description' => ['nullable', 'string', 'max:'.self::MAX_JOB_DESCRIPTION_LENGTH],
            'stage' => [Rule::requiredIf($isCreating), Rule::enum(ApplicationStage::class)],
            'lock_version' => [Rule::requiredIf(! $isCreating), 'integer'],
        ];
    }

    /**
     * @return array{company_name: string, role_title: string, source_url: ?string, location: ?string, employment_type: ?string, salary_text: ?string, deadline_on: ?string, notes: ?string}
     */
    public function details(): array
    {
        return [
            'company_name' => $this->string('company_name')->toString(),
            'role_title' => $this->string('role_title')->toString(),
            'source_url' => $this->filled('source_url') ? trim($this->string('source_url')->toString()) : null,
            'location' => $this->input('location'),
            'employment_type' => $this->input('employment_type'),
            'salary_text' => $this->input('salary_text'),
            'deadline_on' => $this->input('deadline_on'),
            'notes' => $this->input('notes'),
        ];
    }

    public function jobDescription(): ?string
    {
        return $this->input('job_description');
    }

    /**
     * @return array<string, bool>
     */
    protected function plainTextFields(): array
    {
        return [
            'company_name' => false,
            'role_title' => false,
            'location' => false,
            'salary_text' => false,
            'notes' => true,
            'job_description' => true,
        ];
    }
}
