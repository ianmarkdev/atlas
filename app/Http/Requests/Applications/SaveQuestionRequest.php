<?php

namespace App\Http\Requests\Applications;

use App\Concerns\NormalizesPlainText;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Add or edit one question of an application form, with optional word and character limits.
 */
class SaveQuestionRequest extends FormRequest
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
            'prompt_text' => ['required', 'string', 'max:2000'],
            'word_limit' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'char_limit' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ];
    }

    public function wordLimit(): ?int
    {
        return $this->filled('word_limit') ? $this->integer('word_limit') : null;
    }

    public function charLimit(): ?int
    {
        return $this->filled('char_limit') ? $this->integer('char_limit') : null;
    }

    /**
     * @return array<string, bool>
     */
    protected function plainTextFields(): array
    {
        return ['prompt_text' => true];
    }
}
