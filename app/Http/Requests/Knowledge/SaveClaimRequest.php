<?php

namespace App\Http\Requests\Knowledge;

use App\Concerns\NormalizesPlainText;
use App\Domain\Knowledge\ClaimKind;
use App\Domain\Knowledge\ClaimSubject;
use App\Domain\Knowledge\Employment;
use App\Domain\Knowledge\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create and edit a single claim. The subject (`employment:{ulid}`, `project:{ulid}` or empty
 * for general) is set on create only; moving a claim between subjects is not supported.
 */
class SaveClaimRequest extends FormRequest
{
    use NormalizesPlainText;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $isUpdate = $this->route('claim') !== null;

        return [
            'statement' => ['required', 'string', 'max:1000'],
            'kind' => ['required', Rule::enum(ClaimKind::class)],
            'subject' => ['nullable', 'string', 'max:64'],
            'change_reason' => ['nullable', 'string', 'max:255'],
            'allow_near_duplicate' => ['boolean'],
            'lock_version' => [Rule::requiredIf($isUpdate), 'integer'],
            // A VA's note to the candidate when suggesting a change to a verified item.
            'rationale' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->route('claim') === null && ClaimSubject::resolve($this->input('subject')) === false) {
                    $validator->errors()->add('subject', __('Choose an employment or project from your record.'));
                }
            },
        ];
    }

    public function subjectRecord(): Employment|Project|null
    {
        return ClaimSubject::resolve($this->input('subject')) ?: null;
    }

    protected function plainTextFields(): array
    {
        return ['statement' => false, 'change_reason' => false, 'rationale' => true];
    }
}
