<?php

namespace App\Http\Requests\Api;

use App\Domain\Shared\PlainText;
use App\Http\Requests\Applications\SaveJobApplicationRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * A job listing captured by the browser extension. Same fields, limits and normalisation as the
 * "add application" form, notes and the how-to-apply fields included, but without the stage: a
 * capture is always a new, `saved` application. The extension's extra findings arrive under
 * `capture`.
 */
class CaptureJobApplicationRequest extends SaveJobApplicationRequest
{
    public const EXTRACTION_METHODS = ['adapter', 'json-ld', 'meta', 'dom', 'fallback', 'manual'];

    public const WORKPLACE_TYPES = ['remote', 'hybrid', 'on_site'];

    public const PAY_PERIODS = ['hour', 'day', 'week', 'month', 'year'];

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...Arr::except(parent::rules(), ['stage', 'lock_version']),
            // Some listings do not name the employer (agencies, anonymised ads).
            'company_name' => ['nullable', 'string', 'max:200'],
            'allow_duplicate' => ['sometimes', 'boolean'],
            'capture' => ['sometimes', 'nullable', 'array:method,site,external_id,posted_at,workplace_type,salary,captured_at'],
            'capture.method' => ['nullable', Rule::in(self::EXTRACTION_METHODS)],
            'capture.site' => ['nullable', 'string', 'max:255'],
            'capture.external_id' => ['nullable', 'string', 'max:200'],
            'capture.posted_at' => ['nullable', 'date'],
            'capture.workplace_type' => ['nullable', Rule::in(self::WORKPLACE_TYPES)],
            'capture.salary' => ['nullable', 'array:min,max,currency,period'],
            'capture.salary.min' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'capture.salary.max' => ['nullable', 'numeric', 'min:0', 'max:100000000', Rule::when(is_numeric($this->input('capture.salary.min')), ['gte:capture.salary.min'])],
            'capture.salary.currency' => ['nullable', 'string', 'regex:/^[A-Z]{3}$/'],
            'capture.salary.period' => ['nullable', Rule::in(self::PAY_PERIODS)],
            'capture.captured_at' => ['nullable', 'date'],
        ];
    }

    /**
     * The company is null when the listing did not name it.
     *
     * @return array{company_name: ?string, role_title: string, source_url: ?string, location: ?string, employment_type: ?string, salary_text: ?string, deadline_on: ?string, source: ?string, subject_line: ?string, apply_requirement: ?string, proposal_angle: ?string, notes: ?string}
     */
    public function details(): array
    {
        return [
            ...parent::details(),
            'company_name' => $this->filled('company_name') ? $this->string('company_name')->toString() : null,
        ];
    }

    public function allowsDuplicate(): bool
    {
        return $this->boolean('allow_duplicate');
    }

    /**
     * Identifiers and small structured values for the audit log (never content bodies).
     *
     * @return array<string, mixed>
     */
    public function captureMetadata(): array
    {
        $text = fn (string $key): ?string => is_string($value = $this->input("capture.{$key}")) && ($clean = PlainText::normalize($value)) !== '' ? $clean : null;
        $salary = array_filter([
            'min' => $this->input('capture.salary.min'),
            'max' => $this->input('capture.salary.max'),
            'currency' => $this->input('capture.salary.currency'),
            'period' => $this->input('capture.salary.period'),
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        return [
            'via' => 'browser_extension',
            'method' => $this->input('capture.method'),
            'site' => $text('site'),
            'external_id' => $text('external_id'),
            'posted_at' => $this->input('capture.posted_at'),
            'workplace_type' => $this->input('capture.workplace_type'),
            'salary' => $salary === [] ? null : $salary,
            'captured_at' => $this->input('capture.captured_at'),
        ];
    }
}
