<?php

namespace App\Http\Requests\Knowledge;

use App\Domain\Knowledge\ClaimKind;
use App\Domain\Knowledge\ClaimSubject;
use App\Domain\Knowledge\Employment;
use App\Domain\Knowledge\Project;
use App\Domain\Shared\PlainText;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Bulk add: one claim per line, split deterministically (no AI). Leading bullet characters
 * ("-", "*", "•", "1.") are removed so a pasted CV list works as-is.
 */
class BulkStoreClaimsRequest extends FormRequest
{
    public const MAX_LINES = 100;

    public const MAX_LINE_LENGTH = 1000;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'statements' => ['required', 'string', 'max:100000'],
            'kind' => ['required', Rule::enum(ClaimKind::class)],
            'subject' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (ClaimSubject::resolve($this->input('subject')) === false) {
                    $validator->errors()->add('subject', __('Choose an employment or project from your record.'));
                }

                $lines = $this->statementLines();

                if ($lines === []) {
                    $validator->errors()->add('statements', __('Add at least one claim, one per line.'));
                } elseif (count($lines) > self::MAX_LINES) {
                    $validator->errors()->add('statements', __('Add at most :max claims at a time.', ['max' => self::MAX_LINES]));
                }

                foreach ($lines as $number => $line) {
                    if (mb_strlen($line) > self::MAX_LINE_LENGTH) {
                        $validator->errors()->add('statements', __('Line :line is longer than :max characters.', ['line' => $number + 1, 'max' => self::MAX_LINE_LENGTH]));
                    }
                }
            },
        ];
    }

    /**
     * Normalised, non-empty lines with list markers removed.
     *
     * @return list<string>
     */
    public function statementLines(): array
    {
        $text = PlainText::normalize((string) $this->input('statements', ''), multiline: true);

        $lines = array_map(
            fn (string $line): string => trim((string) preg_replace('/^\s*(?:[-*•·–]|\d+[.)])\s+/u', '', $line)),
            explode("\n", $text),
        );

        return array_values(array_filter($lines, fn (string $line): bool => $line !== ''));
    }

    public function subjectRecord(): Employment|Project|null
    {
        return ClaimSubject::resolve($this->input('subject')) ?: null;
    }
}
