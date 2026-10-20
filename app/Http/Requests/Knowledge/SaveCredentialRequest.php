<?php

namespace App\Http\Requests\Knowledge;

use App\Concerns\NormalizesPlainText;
use App\Domain\Knowledge\CredentialType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create and edit education or a certification. Months come from `<input type="month">` (YYYY-MM).
 */
class SaveCredentialRequest extends FormRequest
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
            'type' => ['required', Rule::enum(CredentialType::class)],
            'institution' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'field_of_study' => ['nullable', 'string', 'max:255'],
            'start_date' => ['nullable', 'date_format:Y-m'],
            'end_date' => ['nullable', 'date_format:Y-m', 'after_or_equal:start_date'],
            'expires_on' => ['nullable', 'date_format:Y-m'],
            'credential_url' => ['nullable', 'url:http,https', 'max:2048'],
            'summary' => ['nullable', 'string', 'max:2000'],
            'lock_version' => [Rule::requiredIf($this->route('credential') !== null), 'integer'],
            // A VA's note to the candidate when suggesting a change to a verified item.
            'rationale' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Validated model attributes, with months converted to dates.
     *
     * @return array<string, mixed>
     */
    public function credentialAttributes(): array
    {
        $validated = $this->safe()->except(['lock_version', 'rationale']);
        $month = fn (?string $value): ?string => $value === null ? null : "{$value}-01";

        return [
            ...$validated,
            'start_date' => $month($validated['start_date'] ?? null),
            'end_date' => $month($validated['end_date'] ?? null),
            'expires_on' => $month($validated['expires_on'] ?? null),
        ];
    }

    protected function plainTextFields(): array
    {
        return [
            'institution' => false,
            'name' => false,
            'field_of_study' => false,
            'credential_url' => false,
            'summary' => true,
            'rationale' => true,
        ];
    }
}
