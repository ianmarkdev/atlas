<?php

namespace App\Http\Requests\Knowledge;

use App\Concerns\NormalizesPlainText;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCandidateProfileRequest extends FormRequest
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
            'display_name' => ['required', 'string', 'max:255'],
            'headline' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function plainTextFields(): array
    {
        return ['display_name' => false, 'headline' => false];
    }
}
