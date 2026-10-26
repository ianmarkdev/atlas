<?php

namespace App\Domain\Knowledge;

/**
 * A career document in Markdown, read into library records for LibraryImporter. Two layouts:
 *
 * - a profile: `# … Employment` with one `## …` block per job and `# … Projects` with one
 *   `## Project N: Name` block per project, each a list of `- **Field:** value` lines, then
 *   `# … Claims` with `### Subject — kind` headings over bullet lists;
 * - a CV (no such sections): `### Employer — Title` headings over bullet lists, where a
 *   `**Name**` line switches to that project. `Tech:` lines are left out.
 *
 * Fields with no column of their own (scale, impact, technologies, …) are kept in the summary,
 * which is what the search index reads. "unknown" counts as no value.
 *
 * @phpstan-type ParsedEmployment array{employer_name: string, title: string, employment_type: ?EmploymentType, location: ?string, start_date: ?string, end_date: ?string, date_precision: string, summary: ?string}
 * @phpstan-type ParsedProject array{name: string, employment: ?string, client_name: ?string, role: ?string, start_date: ?string, end_date: ?string, url: ?string, summary: ?string}
 * @phpstan-type ParsedClaim array{subject: string, kind: ClaimKind, statement: string}
 */
final class LibraryDocument
{
    /**
     * @param  list<ParsedEmployment>  $employments
     * @param  list<ParsedProject>  $projects
     * @param  list<ParsedClaim>  $claims
     */
    public function __construct(public array $employments, public array $projects, public array $claims) {}

    public static function parse(string $markdown): self
    {
        $sections = self::split(str_replace("\r\n", "\n", $markdown), '#');
        $employments = [];
        $projects = [];
        $claims = [];

        foreach ($sections as $heading => $body) {
            $heading = mb_strtolower($heading);
            $blocks = self::split($body, '##');

            if (str_contains($heading, 'employment')) {
                $employments = array_values(array_filter(array_map(self::employment(...), $blocks)));
            } elseif (str_contains($heading, 'project')) {
                $projects = array_map(self::project(...), array_values($blocks), array_keys($blocks));
            } elseif (str_contains($heading, 'claim')) {
                $claims = self::claims($body);
            }
        }

        if ($sections === []) {
            $claims = self::claims($markdown);
        }

        return new self($employments, $projects, $claims);
    }

    /**
     * The blocks under each heading of exactly this level, keyed by the heading text.
     *
     * @return array<string, string>
     */
    private static function split(string $markdown, string $level): array
    {
        $parts = preg_split('/^'.preg_quote($level, '/').'\s+(.+)$/mu', $markdown, flags: PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $blocks = [];

        for ($index = 1; $index + 1 < count($parts); $index += 2) {
            $blocks[trim($parts[$index])] = $parts[$index + 1];
        }

        return $blocks;
    }

    /**
     * @return ParsedEmployment|null null when the block names no employer or title
     */
    private static function employment(string $block): ?array
    {
        $fields = self::fields($block);
        $employer = self::take($fields, 'Employer');
        $title = self::take($fields, 'Title');
        [$start, $precision] = self::date(self::take($fields, 'Start date')) ?? [null, 'month'];

        if ($employer === null || $title === null) {
            return null;
        }

        return [
            'employer_name' => $employer,
            'title' => $title,
            'employment_type' => EmploymentType::tryFrom((string) self::take($fields, 'Type')),
            'location' => self::take($fields, 'Location'),
            'start_date' => $start,
            'end_date' => self::date(self::take($fields, 'End date'))[0] ?? null,
            'date_precision' => $precision,
            'summary' => self::summary($fields),
        ];
    }

    /**
     * @return ParsedProject
     */
    private static function project(string $block, string $heading): array
    {
        $fields = self::fields($block);
        [$start, $end] = array_pad(explode('/', (string) self::take($fields, 'Start date / End date')), 2, null);
        $link = self::take($fields, 'URL');
        preg_match_all('#https?://[^\s;,`]+#u', (string) $link, $urls);

        if (count($urls[0]) > 1) {
            $fields['URL'] = $link;
        }

        return [
            'name' => trim((string) preg_replace('/^Project\s+\d+\s*:\s*/iu', '', $heading)),
            'employment' => self::take($fields, 'Employment'),
            'client_name' => self::take($fields, 'Client'),
            'role' => self::take($fields, 'Role'),
            'start_date' => self::date($start)[0] ?? null,
            'end_date' => self::date($end)[0] ?? null,
            'url' => $urls[0][0] ?? null,
            'summary' => self::summary($fields),
        ];
    }

    /**
     * Bullet points under `### Subject — kind` (profile) or `### Employer — Title` and `**Project**`
     * lines (CV). Without a kind, a bullet with a number is an achievement.
     *
     * @return list<ParsedClaim>
     */
    private static function claims(string $markdown): array
    {
        $claims = [];
        $subject = null;
        $kind = null;

        foreach (explode("\n", $markdown) as $line) {
            $line = trim($line);

            if (preg_match('/^###\s+(.+?)(?:\s+—\s+(.+))?$/u', $line, $heading) === 1) {
                $subject = trim($heading[1]);
                $kind = ClaimKind::tryFrom(mb_strtolower(trim($heading[2] ?? '')));
            } elseif (preg_match('/^\*\*(.+?)\*\*/u', $line, $project) === 1) {
                $subject = trim($project[1]);
            } elseif ($subject !== null && preg_match('/^[-*]\s+(.+)$/u', $line, $bullet) === 1 && preg_match('/^tech\s*:/iu', $bullet[1]) !== 1) {
                $statement = trim($bullet[1]);
                $claims[] = [
                    'subject' => $subject,
                    'kind' => $kind ?? (preg_match('/\d/', $statement) === 1 ? ClaimKind::Achievement : ClaimKind::Responsibility),
                    'statement' => $statement,
                ];
            }
        }

        return $claims;
    }

    /**
     * `- **Field:** value` lines in order; other text continues the field before it.
     *
     * @return array<string, string>
     */
    private static function fields(string $block): array
    {
        $fields = [];
        $current = null;

        foreach (explode("\n", $block) as $line) {
            $line = trim($line);

            if (preg_match('/^-\s+\*\*(.+?):\*\*\s*(.*)$/u', $line, $field) === 1) {
                $current = trim($field[1]);
                $fields[$current] = trim($field[2]);
            } elseif ($current !== null && $line !== '') {
                $fields[$current] = trim($fields[$current].' '.ltrim($line, '- '));
            }
        }

        return $fields;
    }

    /**
     * Remove a field and return its value (null when missing or unknown).
     *
     * @param  array<string, string>  $fields
     */
    private static function take(array &$fields, string $name): ?string
    {
        $value = trim(str_replace('`', '', $fields[$name] ?? ''));
        unset($fields[$name]);

        return $value === '' || in_array(mb_strtolower($value), ['unknown', 'n/a'], true) ? null : $value;
    }

    /**
     * The summary, then every other field with a value as "Field: value".
     *
     * @param  array<string, string>  $fields
     */
    private static function summary(array $fields): ?string
    {
        $parts = [self::take($fields, 'Summary')];

        foreach (array_keys($fields) as $name) {
            $value = self::take($fields, $name);
            $parts[] = $value === null ? null : "{$name}: {$value}";
        }

        $summary = implode("\n", array_filter($parts));

        return $summary === '' ? null : $summary;
    }

    /**
     * "2024", "2024-03" or "2024-03-15" as a date and its precision; null for "present" or unknown.
     *
     * @return array{string, string}|null
     */
    private static function date(?string $value): ?array
    {
        return match (true) {
            $value === null => null,
            preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($value), $date) === 1 => ["{$date[1]}-{$date[2]}-{$date[3]}", 'day'],
            preg_match('/^(\d{4})-(\d{2})$/', trim($value), $date) === 1 => ["{$date[1]}-{$date[2]}-01", 'month'],
            preg_match('/^(\d{4})$/', trim($value), $date) === 1 => ["{$date[1]}-01-01", 'year'],
            default => null,
        };
    }
}
