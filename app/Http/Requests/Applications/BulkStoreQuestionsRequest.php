<?php

namespace App\Http\Requests\Applications;

use App\Domain\Shared\PlainText;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Paste the questions of an application form, one per line. A limit written at the end of a
 * line is picked up: "Why us? (300 words)", "Tell us about yourself (max 1,500 characters)".
 */
class BulkStoreQuestionsRequest extends FormRequest
{
    public const MAX_QUESTIONS = 50;

    private const LIMIT = '/\s*[(\[]\s*(?:max(?:imum)?\.?\s*|up to\s*|≤\s*)?(\d[\d,.\s]*)\s*(words?|characters?|chars?)\s*(?:max(?:imum)?)?\s*[)\]]\s*$/iu';

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'questions' => ['required', 'string', 'max:100000'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $questions = $this->parsedQuestions();

                if ($questions === []) {
                    $validator->errors()->add('questions', __('Add at least one question.'));
                } elseif (count($questions) > self::MAX_QUESTIONS) {
                    $validator->errors()->add('questions', __('Add at most :max questions at a time.', ['max' => self::MAX_QUESTIONS]));
                }

                foreach ($questions as $number => $question) {
                    if (mb_strlen($question['prompt']) > 2000) {
                        $validator->errors()->add('questions', __('Question :n is longer than 2,000 characters.', ['n' => $number + 1]));
                    }
                }
            },
        ];
    }

    /**
     * @return list<array{prompt: string, word_limit: ?int, char_limit: ?int}>
     */
    public function parsedQuestions(): array
    {
        $text = PlainText::normalize((string) $this->input('questions', ''), multiline: true);
        $questions = [];

        foreach (explode("\n", $text) as $line) {
            $line = trim((string) preg_replace('/^\s*(?:[-*•]|\d+[.)])\s+/u', '', $line));
            $wordLimit = null;
            $charLimit = null;

            if (preg_match(self::LIMIT, $line, $match) === 1) {
                $limit = (int) preg_replace('/\D/', '', $match[1]);
                $isWords = str_starts_with(mb_strtolower($match[2]), 'word');
                $wordLimit = $isWords && $limit > 0 ? $limit : null;
                $charLimit = ! $isWords && $limit > 0 ? $limit : null;
                $line = trim(mb_substr($line, 0, mb_strlen($line) - mb_strlen($match[0])));
            }

            if ($line !== '') {
                $questions[] = ['prompt' => $line, 'word_limit' => $wordLimit, 'char_limit' => $charLimit];
            }
        }

        return $questions;
    }
}
