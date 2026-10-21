<?php

namespace App\Domain\Ai;

/**
 * Validates decoded JSON against the subset of JSON Schema our prompt schemas use: `type`
 * (single or list), `properties`, `required`, `additionalProperties: false`, `items`, `enum`,
 * `minLength`/`maxLength`, `minimum`/`maximum`, `minItems`/`maxItems`. Model output is never
 * trusted to follow its schema; this is the check (no extra dependency).
 */
class JsonSchemaValidator
{
    /**
     * @param  array<string, mixed>  $schema
     * @return list<string> errors like "$.segments[0].claims: is required"; empty when valid
     */
    public function validate(mixed $value, array $schema, string $path = '$'): array
    {
        $errors = [];
        $types = isset($schema['type']) ? array_values(array_filter((array) $schema['type'], is_string(...))) : [];

        if ($types !== [] && ! $this->matchesAny($value, $types)) {
            return ["{$path}: expected ".implode(' or ', $types).', got '.$this->typeOf($value)];
        }

        if (isset($schema['enum']) && is_array($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $errors[] = "{$path}: must be one of ".implode(', ', array_map(fn ($option) => json_encode($option), $schema['enum']));
        }

        if (is_string($value)) {
            $length = mb_strlen($value);

            if (isset($schema['minLength']) && $length < $schema['minLength']) {
                $errors[] = "{$path}: shorter than {$schema['minLength']} characters";
            }

            if (isset($schema['maxLength']) && $length > $schema['maxLength']) {
                $errors[] = "{$path}: longer than {$schema['maxLength']} characters";
            }
        }

        if (is_int($value) || is_float($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                $errors[] = "{$path}: below {$schema['minimum']}";
            }

            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                $errors[] = "{$path}: above {$schema['maximum']}";
            }
        }

        if (is_array($value) && array_is_list($value) && $this->expects($types, 'array')) {
            if (isset($schema['minItems']) && count($value) < $schema['minItems']) {
                $errors[] = "{$path}: needs at least {$schema['minItems']} items";
            }

            if (isset($schema['maxItems']) && count($value) > $schema['maxItems']) {
                $errors[] = "{$path}: has more than {$schema['maxItems']} items";
            }

            if (isset($schema['items']) && is_array($schema['items'])) {
                foreach ($value as $i => $item) {
                    array_push($errors, ...$this->validate($item, $schema['items'], "{$path}[{$i}]"));
                }
            }
        }

        if (is_array($value) && $this->expects($types, 'object')) {
            $properties = (array) ($schema['properties'] ?? []);

            foreach ((array) ($schema['required'] ?? []) as $required) {
                if (! array_key_exists($required, $value)) {
                    $errors[] = "{$path}.{$required}: is required";
                }
            }

            foreach ($value as $key => $item) {
                if (isset($properties[$key]) && is_array($properties[$key])) {
                    array_push($errors, ...$this->validate($item, $properties[$key], "{$path}.{$key}"));
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    $errors[] = "{$path}.{$key}: is not allowed";
                }
            }
        }

        return $errors;
    }

    /**
     * @param  list<string>  $types
     */
    private function matchesAny(mixed $value, array $types): bool
    {
        foreach ($types as $type) {
            $matches = match ($type) {
                'object' => is_array($value) && ($value === [] || ! array_is_list($value)),
                'array' => is_array($value) && array_is_list($value),
                'string' => is_string($value),
                'integer' => is_int($value),
                'number' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'null' => $value === null,
                default => false,
            };

            if ($matches) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $types
     */
    private function expects(array $types, string $type): bool
    {
        return $types === [] || in_array($type, $types, true);
    }

    private function typeOf(mixed $value): string
    {
        return match (true) {
            is_array($value) => array_is_list($value) && $value !== [] ? 'array' : 'object',
            is_string($value) => 'string',
            is_int($value) => 'integer',
            is_float($value) => 'number',
            is_bool($value) => 'boolean',
            $value === null => 'null',
            default => get_debug_type($value),
        };
    }
}
