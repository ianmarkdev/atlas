<?php

namespace App\Domain\Resumes;

/**
 * What a tailored resume changed compared with the resume it was copied from: title and
 * summary, jobs and projects added, removed or reordered, display titles and descriptions,
 * bullet points added, removed or reworded, and the other sections. Labels come from the editor
 * library; items no longer in it are named as such.
 */
class ResumeDiff
{
    /** @var array<string, string> */
    private array $labels = [];

    /** @var list<array{section: string, change: string, item: string, before: ?string, after: ?string}> */
    private array $changes = [];

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $library  as given to the resume editor
     * @return list<array{section: string, change: string, item: string, before: ?string, after: ?string}>
     */
    public function between(array $before, array $after, array $library): array
    {
        $this->labels = self::labels($library);
        $this->changes = [];

        foreach (['title' => 'Title', 'summary' => 'Summary'] as $field => $label) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                $this->add('Header', 'changed', $label, $before[$field] ?? null, $after[$field] ?? null);
            }
        }

        $this->compareJobs((array) ($before['experience'] ?? []), (array) ($after['experience'] ?? []));
        $this->compareProjects('Other projects', (array) ($before['projects'] ?? []), (array) ($after['projects'] ?? []));
        $this->compareSet('Skills', self::skillUlids($before), self::skillUlids($after));

        foreach (['education' => 'Education', 'certifications' => 'Certifications'] as $key => $section) {
            $this->compareSet($section, array_column((array) ($before[$key] ?? []), 'credential'), array_column((array) ($after[$key] ?? []), 'credential'));
        }

        foreach (['languages' => 'Languages', 'links' => 'Links'] as $key => $section) {
            $this->compareSet($section, array_values((array) ($before[$key] ?? [])), array_values((array) ($after[$key] ?? [])));
        }

        return $this->changes;
    }

    /**
     * @param  array<mixed>  $before
     * @param  array<mixed>  $after
     */
    private function compareJobs(array $before, array $after): void
    {
        $old = self::byKey($before, 'employment');
        $new = self::byKey($after, 'employment');

        $this->compareMembership('Experience', array_keys($old), array_keys($new));

        foreach (array_intersect_key($new, $old) as $ulid => $job) {
            $name = $this->label($ulid);
            $previous = $old[$ulid];

            if (($previous['display_title'] ?? null) !== ($job['display_title'] ?? null)) {
                $this->add('Experience', 'changed', "{$name}: shown as", $previous['display_title'] ?? null, $job['display_title'] ?? null);
            }

            if (($previous['description'] ?? null) !== ($job['description'] ?? null)) {
                $this->add('Experience', 'changed', "{$name}: description", $previous['description'] ?? null, $job['description'] ?? null);
            }

            $this->compareBullets('Experience', $name, (array) ($previous['bullets'] ?? []), (array) ($job['bullets'] ?? []));
            $this->compareProjects("Projects at {$name}", (array) ($previous['projects'] ?? []), (array) ($job['projects'] ?? []));
        }
    }

    /**
     * @param  array<mixed>  $before
     * @param  array<mixed>  $after
     */
    private function compareProjects(string $section, array $before, array $after): void
    {
        $old = self::byKey($before, 'project');
        $new = self::byKey($after, 'project');

        $this->compareMembership($section, array_keys($old), array_keys($new));

        foreach (array_intersect_key($new, $old) as $ulid => $project) {
            $name = $this->label($ulid);

            if (($old[$ulid]['description'] ?? null) !== ($project['description'] ?? null)) {
                $this->add($section, 'changed', "{$name}: description", $old[$ulid]['description'] ?? null, $project['description'] ?? null);
            }

            $this->compareBullets($section, $name, (array) ($old[$ulid]['bullets'] ?? []), (array) ($project['bullets'] ?? []));
        }
    }

    /**
     * @param  array<mixed>  $before
     * @param  array<mixed>  $after
     */
    private function compareBullets(string $section, string $owner, array $before, array $after): void
    {
        $old = self::byKey($before, 'claim');
        $new = self::byKey($after, 'claim');

        foreach (array_diff_key($new, $old) as $ulid => $bullet) {
            $this->add($section, 'added', "{$owner}: bullet point", null, $bullet['text'] ?? $this->label($ulid));
        }

        foreach (array_diff_key($old, $new) as $ulid => $bullet) {
            $this->add($section, 'removed', "{$owner}: bullet point", $bullet['text'] ?? $this->label($ulid), null);
        }

        foreach (array_intersect_key($new, $old) as $ulid => $bullet) {
            if (($old[$ulid]['text'] ?? null) !== ($bullet['text'] ?? null)) {
                $this->add($section, 'reworded', "{$owner}: bullet point", $old[$ulid]['text'] ?? $this->label($ulid), $bullet['text'] ?? $this->label($ulid));
            }
        }

        if (self::orderChanged(array_keys($old), array_keys($new))) {
            $this->add($section, 'reordered', "{$owner}: bullet points", null, null);
        }
    }

    /**
     * Items added, removed, and whether the shared ones changed order.
     *
     * @param  list<string>  $before
     * @param  list<string>  $after
     */
    private function compareMembership(string $section, array $before, array $after): void
    {
        foreach (array_diff($after, $before) as $ulid) {
            $this->add($section, 'added', $this->label($ulid), null, null);
        }

        foreach (array_diff($before, $after) as $ulid) {
            $this->add($section, 'removed', $this->label($ulid), null, null);
        }

        if (self::orderChanged($before, $after)) {
            $this->add($section, 'reordered', $section, null, null);
        }
    }

    /**
     * @param  list<string>  $before
     * @param  list<string>  $after
     */
    private function compareSet(string $section, array $before, array $after): void
    {
        $before = array_values(array_filter($before, is_string(...)));
        $after = array_values(array_filter($after, is_string(...)));

        foreach (array_diff($after, $before) as $ulid) {
            $this->add($section, 'added', $this->label($ulid), null, null);
        }

        foreach (array_diff($before, $after) as $ulid) {
            $this->add($section, 'removed', $this->label($ulid), null, null);
        }
    }

    private function add(string $section, string $change, string $item, ?string $before, ?string $after): void
    {
        $this->changes[] = ['section' => $section, 'change' => $change, 'item' => $item, 'before' => $before, 'after' => $after];
    }

    private function label(string $ulid): string
    {
        return $this->labels[$ulid] ?? __('(no longer in your library)');
    }

    /**
     * @param  list<string>  $before
     * @param  list<string>  $after
     */
    private static function orderChanged(array $before, array $after): bool
    {
        $common = array_values(array_intersect($before, $after));

        return $common !== array_values(array_intersect($after, $before));
    }

    /**
     * @param  array<mixed>  $items
     * @return array<string, array<string, mixed>>
     */
    private static function byKey(array $items, string $key): array
    {
        $keyed = [];

        foreach ($items as $item) {
            if (is_array($item) && is_string($item[$key] ?? null)) {
                $keyed[$item[$key]] = $item;
            }
        }

        return $keyed;
    }

    /**
     * @param  array<string, mixed>  $document
     * @return list<string>
     */
    private static function skillUlids(array $document): array
    {
        $ulids = [];

        foreach ((array) ($document['skills'] ?? []) as $group) {
            foreach ((array) ($group['items'] ?? []) as $ulid) {
                $ulids[] = (string) $ulid;
            }
        }

        return $ulids;
    }

    /**
     * Human labels for every library item, by ULID.
     *
     * @param  array<string, mixed>  $library
     * @return array<string, string>
     */
    private static function labels(array $library): array
    {
        $labels = [];
        $claims = function (array $record) use (&$labels): void {
            foreach ((array) ($record['claims'] ?? []) as $claim) {
                $labels[(string) $claim['ulid']] = (string) $claim['statement'];
            }
        };

        foreach ((array) ($library['employments'] ?? []) as $employment) {
            $labels[(string) $employment['ulid']] = "{$employment['title']} · {$employment['employer_name']}";
            $claims($employment);

            foreach ((array) ($employment['projects'] ?? []) as $project) {
                $labels[(string) $project['ulid']] = (string) $project['name'];
                $claims($project);
            }
        }

        foreach ((array) ($library['standaloneProjects'] ?? []) as $project) {
            $labels[(string) $project['ulid']] = (string) $project['name'];
            $claims($project);
        }

        foreach ([...(array) ($library['education'] ?? []), ...(array) ($library['certifications'] ?? [])] as $credential) {
            $labels[(string) $credential['ulid']] = "{$credential['name']} — {$credential['institution']}";
        }

        foreach ((array) ($library['skills'] ?? []) as $skill) {
            $labels[(string) $skill['ulid']] = (string) $skill['name'];
        }

        foreach ((array) ($library['languages'] ?? []) as $language) {
            $labels[(string) $language['ulid']] = (string) $language['language'];
        }

        foreach ((array) ($library['links'] ?? []) as $link) {
            $labels[(string) $link['ulid']] = (string) $link['label'];
        }

        return $labels;
    }
}
