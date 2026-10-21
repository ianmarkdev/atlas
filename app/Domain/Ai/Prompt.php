<?php

namespace App\Domain\Ai;

/**
 * One versioned prompt from `prompts/{key}/`: system text, user template and output schema.
 */
final readonly class Prompt
{
    /**
     * @param  array<string, mixed>|null  $schema
     */
    public function __construct(
        public string $key,
        public int $version,
        public string $system,
        public string $userTemplate,
        public ?array $schema,
    ) {}

    /**
     * Fill `{{name}}` placeholders. Values are inserted verbatim; callers wrap untrusted text
     * (job descriptions) in the tags the system prompt describes.
     *
     * @param  array<string, string>  $variables
     *
     * @throws LlmConfigurationError for a placeholder without a value
     */
    public function render(array $variables): string
    {
        return (string) preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', function (array $match) use ($variables): string {
            if (! array_key_exists($match[1], $variables)) {
                throw new LlmConfigurationError("Prompt {$this->key} v{$this->version} needs \"{$match[1]}\".");
            }

            return $variables[$match[1]];
        }, $this->userTemplate);
    }
}
