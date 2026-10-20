<?php

namespace App\Concerns;

use App\Domain\Shared\PlainText;

/**
 * For form requests: normalises the listed text fields before validation, so length limits
 * and duplicate checks apply to the cleaned text.
 */
trait NormalizesPlainText
{
    /**
     * Fields to normalise, mapped to whether they are multi-line.
     *
     * @return array<string, bool>
     */
    abstract protected function plainTextFields(): array;

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach ($this->plainTextFields() as $field => $multiline) {
            $value = $this->input($field);

            if (is_string($value)) {
                $clean = PlainText::normalize($value, $multiline);
                $normalized[$field] = $clean === '' ? null : $clean;
            }
        }

        $this->merge($normalized);
    }
}
