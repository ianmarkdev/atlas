<?php

namespace App\Http\Requests\Knowledge;

use App\Domain\Knowledge\AnswerCategory;
use App\Domain\Shared\PlainText;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Paste several question/answer pairs at once. Pairs are separated by a line containing only
 * `---`; in each pair the first line is the question and the rest is the answer. No AI.
 */
class BulkStoreAnswersRequest extends FormRequest
{
    public const MAX_PAIRS = 50;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'pairs' => ['required', 'string', 'max:200000'],
            'category' => ['required', Rule::enum(AnswerCategory::class)],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $pairs = $this->parsedPairs();

                if ($pairs === []) {
                    $validator->errors()->add('pairs', __('Add at least one question with its answer.'));

                    return;
                }

                if (count($pairs) > self::MAX_PAIRS) {
                    $validator->errors()->add('pairs', __('Add at most :max answers at a time.', ['max' => self::MAX_PAIRS]));
                }

                foreach ($pairs as $number => $pair) {
                    $position = $number + 1;

                    if ($pair['body'] === '') {
                        $validator->errors()->add('pairs', __('Answer :n has a question but no answer.', ['n' => $position]));
                    } elseif (mb_strlen($pair['question']) > 500) {
                        $validator->errors()->add('pairs', __('The question of answer :n is longer than 500 characters.', ['n' => $position]));
                    } elseif (mb_strlen($pair['body']) > SaveAnswerRequest::MAX_BODY_LENGTH) {
                        $validator->errors()->add('pairs', __('Answer :n is longer than :max characters.', ['n' => $position, 'max' => SaveAnswerRequest::MAX_BODY_LENGTH]));
                    }
                }
            },
        ];
    }

    /**
     * @return list<array{question: string, body: string}>
     */
    public function parsedPairs(): array
    {
        $text = PlainText::normalize((string) $this->input('pairs', ''), multiline: true);
        $pairs = [];

        foreach (preg_split('/^\s*-{3,}\s*$/m', $text) ?: [] as $block) {
            $lines = explode("\n", trim($block));
            $question = trim((string) array_shift($lines));

            if ($question === '') {
                continue;
            }

            $pairs[] = ['question' => $question, 'body' => trim(implode("\n", $lines))];
        }

        return $pairs;
    }
}
