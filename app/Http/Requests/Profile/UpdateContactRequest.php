<?php

namespace App\Http\Requests\Profile;

use App\Concerns\NormalizesPlainText;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Contact details shown at the top of every resume.
 */
class UpdateContactRequest extends FormRequest
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
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64', 'regex:/^[0-9+().\-\s]+$/'],
            'location' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => __('Use digits, spaces and + ( ) - . only.'),
        ];
    }

    protected function plainTextFields(): array
    {
        return ['display_name' => false, 'email' => false, 'phone' => false, 'location' => false];
    }
}
