<?php

namespace App\Http\Requests\Knowledge;

use App\Concerns\NormalizesPlainText;
use App\Domain\Knowledge\EmploymentType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create and edit an employment. Dates come from `<input type="month">` (YYYY-MM) and are
 * stored as the first day of the month (`date_precision = month`).
 */
class SaveEmploymentRequest extends FormRequest
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
            'employer_name' => ['required', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:255'],
            'employment_type' => ['nullable', Rule::enum(EmploymentType::class)],
            'location' => ['nullable', 'string', 'max:255'],
            'start_date' => ['required', 'date_format:Y-m'],
            'end_date' => ['nullable', 'date_format:Y-m', 'after_or_equal:start_date'],
            'summary' => ['nullable', 'string', 'max:5000'],
            'lock_version' => [Rule::requiredIf($this->route('employment') !== null), 'integer'],
        ];
    }

    /**
     * Validated model attributes, with months converted to dates.
     *
     * @return array<string, mixed>
     */
    public function employmentAttributes(): array
    {
        $validated = $this->safe()->except('lock_version');

        return [
            ...$validated,
            'start_date' => $validated['start_date'].'-01',
            'end_date' => isset($validated['end_date']) ? $validated['end_date'].'-01' : null,
        ];
    }

    protected function plainTextFields(): array
    {
        return [
            'employer_name' => false,
            'title' => false,
            'location' => false,
            'summary' => true,
        ];
    }
}
