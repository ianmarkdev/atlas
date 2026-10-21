<?php

namespace App\Domain\Ai;

/**
 * Prompts live as files: `prompts/{key}/v{n}.system.md`, `v{n}.user.md` and optionally
 * `v{n}.schema.json`. A used version is never edited; changes go into a new version file, and
 * the version is recorded on every generation run.
 */
class PromptRegistry
{
    public function __construct(private ?string $directory = null) {}

    /**
     * @param  int|null  $version  null = the latest version
     *
     * @throws LlmConfigurationError when the prompt or version does not exist
     */
    public function get(string $key, ?int $version = null): Prompt
    {
        $directory = ($this->directory ?? base_path('prompts')).DIRECTORY_SEPARATOR.$key;
        $version ??= $this->latestVersion($directory);
        $base = $directory.DIRECTORY_SEPARATOR."v{$version}";

        if ($version === null || ! is_file("{$base}.system.md") || ! is_file("{$base}.user.md")) {
            throw new LlmConfigurationError("Prompt \"{$key}\"".($version === null ? '' : " v{$version}").' does not exist in prompts/.');
        }

        $schema = null;

        if (is_file("{$base}.schema.json")) {
            $schema = json_decode((string) file_get_contents("{$base}.schema.json"), true, flags: JSON_THROW_ON_ERROR);
        }

        return new Prompt(
            $key,
            $version,
            trim((string) file_get_contents("{$base}.system.md")),
            trim((string) file_get_contents("{$base}.user.md")),
            is_array($schema) ? $schema : null,
        );
    }

    private function latestVersion(string $directory): ?int
    {
        $versions = array_map(
            fn (string $file): int => (int) preg_replace('/^v(\d+)\.system\.md$/', '$1', basename($file)),
            glob($directory.DIRECTORY_SEPARATOR.'v*.system.md') ?: [],
        );

        return $versions === [] ? null : max($versions);
    }
}
