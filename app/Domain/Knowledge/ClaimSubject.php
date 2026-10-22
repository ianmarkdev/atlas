<?php

namespace App\Domain\Knowledge;

/**
 * Parses the subject a claim is about, as sent by the UI: `employment:{ulid}`, `project:{ulid}`
 * or empty for a general claim. Lookups go through the tenant scope, so another workspace's
 * record is simply not found.
 */
class ClaimSubject
{
    /**
     * @return Employment|Project|false|null the live record, null for a general claim, false when invalid
     */
    public static function resolve(?string $value): Employment|Project|false|null
    {
        if ($value === null || $value === '' || $value === 'general') {
            return null;
        }

        [$type, $ulid] = array_pad(explode(':', $value, 2), 2, '');

        $query = match ($type) {
            'employment' => Employment::query(),
            'project' => Project::query(),
            default => null,
        };

        return $query?->notArchived()->where('ulid', $ulid)->first() ?? false;
    }

    public static function key(Employment|Project|null $subject): string
    {
        return $subject === null ? 'general' : $subject->getMorphClass().':'.$subject->ulid;
    }

    /**
     * Choices for "where did this happen?" when a bullet point is added outside the library.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        $options = [['value' => 'general', 'label' => __('General (not tied to a job or project)')]];

        foreach (Employment::query()->notArchived()->orderByDesc('start_date')->get() as $employment) {
            $options[] = ['value' => self::key($employment), 'label' => "{$employment->title} — {$employment->employer_name}"];
        }

        foreach (Project::query()->notArchived()->orderByDesc('start_date')->get() as $project) {
            $options[] = ['value' => self::key($project), 'label' => __('Project: :name', ['name' => $project->name])];
        }

        return $options;
    }
}
