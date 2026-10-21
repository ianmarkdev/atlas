<?php

namespace App\Http\Requests\Applications;

use App\Domain\Resumes\ResumeDocumentValidator;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Save the tailored resume editor: the whole document + the lock version it was loaded at.
 * Checked against the library like a resume variant.
 */
class SaveApplicationResumeRequest extends FormRequest
{
    /** @var array<string, mixed> */
    private array $normalizedDocument = [];

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'document' => ['required', 'array'],
            'lock_version' => ['required', 'integer'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $this->normalizedDocument = app(ResumeDocumentValidator::class)->validate($this->input('document'));
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function document(): array
    {
        return $this->normalizedDocument;
    }
}
