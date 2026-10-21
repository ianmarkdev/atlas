<?php

namespace App\Http\Requests\Applications;

use App\Concerns\NormalizesPlainText;
use App\Domain\Knowledge\AnswerLibraryEntry;
use App\Domain\Knowledge\AnswerStatus;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Save the answer to one question. `library_entry` is the answer-library entry the text was
 * inserted from, if any (kept as provenance).
 */
class SaveAnswerDraftRequest extends FormRequest
{
    use NormalizesPlainText;

    public const MAX_BODY_LENGTH = 50000;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'body' => ['nullable', 'string', 'max:'.self::MAX_BODY_LENGTH],
            'library_entry' => [
                'nullable',
                'string',
                Rule::exists('answer_library_entries', 'ulid')
                    ->where('workspace_id', app(TenantContext::class)->workspaceId())
                    ->whereNot('status', AnswerStatus::Archived->value),
            ],
            'lock_version' => ['required', 'integer'],
        ];
    }

    public function body(): string
    {
        return (string) $this->input('body', '');
    }

    public function libraryEntry(): ?AnswerLibraryEntry
    {
        return $this->filled('library_entry')
            ? AnswerLibraryEntry::query()->where('ulid', $this->string('library_entry')->toString())->first()
            : null;
    }

    /**
     * @return array<string, bool>
     */
    protected function plainTextFields(): array
    {
        return ['body' => true];
    }
}
