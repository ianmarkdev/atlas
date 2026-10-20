<?php

namespace App\Domain\Resumes;

/**
 * Which active resumes include each library item, read from their current documents
 * ("Used in: PHP focused, React focused").
 */
class ResumeUsage
{
    /**
     * @return array{employments: array<string, list<string>>, projects: array<string, list<string>>, claims: array<string, list<string>>, credentials: array<string, list<string>>, skills: array<string, list<string>>}
     */
    public static function map(?int $exceptResumeId = null): array
    {
        $employments = [];
        $projects = [];
        $claims = [];
        $credentials = [];
        $skills = [];

        $resumes = ResumeVariant::query()
            ->active()
            ->when($exceptResumeId !== null, fn ($query) => $query->whereKeyNot($exceptResumeId))
            ->with('currentRevision')
            ->orderBy('name')
            ->get();

        foreach ($resumes as $resume) {
            $document = $resume->document();
            $items = [];

            foreach ($document['experience'] ?? [] as $job) {
                self::add($employments, $job['employment'] ?? null, $resume->name);
                $items[] = $job;
                array_push($items, ...($job['projects'] ?? []));
            }
            array_push($items, ...($document['projects'] ?? []));

            foreach ($items as $item) {
                self::add($projects, $item['project'] ?? null, $resume->name);

                foreach ($item['bullets'] ?? [] as $bullet) {
                    self::add($claims, $bullet['claim'] ?? null, $resume->name);
                }
            }

            foreach ([...$document['education'], ...$document['certifications']] as $item) {
                self::add($credentials, $item['credential'] ?? null, $resume->name);
            }

            foreach ($document['skills'] as $group) {
                foreach ($group['items'] ?? [] as $skillUlid) {
                    self::add($skills, $skillUlid, $resume->name);
                }
            }
        }

        return ['employments' => $employments, 'projects' => $projects, 'claims' => $claims, 'credentials' => $credentials, 'skills' => $skills];
    }

    /**
     * @param  array<string, list<string>>  $map
     */
    private static function add(array &$map, mixed $ulid, string $resumeName): void
    {
        if (! is_string($ulid)) {
            return;
        }

        if (! in_array($resumeName, $map[$ulid] ?? [], true)) {
            $map[$ulid][] = $resumeName;
        }
    }
}
