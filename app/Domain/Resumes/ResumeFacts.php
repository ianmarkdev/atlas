<?php

namespace App\Domain\Resumes;

/**
 * Cuts the editor's library down to the facts one resume document points at. Stored with each
 * tailored resume version, so an approved or sent resume can be shown exactly as it was even
 * after the library changes.
 */
class ResumeFacts
{
    /**
     * @param  array<string, mixed>  $library  as given to the resume editor (employments with claims and projects, standalone projects, credentials, skills, languages, links)
     * @param  array<string, mixed>  $contact
     * @param  array<string, mixed>  $document  a validated resume document
     * @return array{contact: array<string, mixed>, library: array<string, list<array<string, mixed>>>}
     */
    public static function snapshot(array $library, array $contact, array $document): array
    {
        $refs = self::references($document);
        $keep = fn (array $items, string $kind): array => array_values(array_filter(
            $items,
            fn (mixed $item): bool => is_array($item) && isset($refs[$kind][$item['ulid'] ?? '']),
        ));
        $withClaims = fn (array $record): array => [
            ...$record,
            'claims' => $keep($record['claims'] ?? [], 'claims'),
        ];

        $employments = array_map(fn (array $employment): array => [
            ...$withClaims($employment),
            'projects' => array_map($withClaims, $keep($employment['projects'] ?? [], 'projects')),
        ], $keep($library['employments'] ?? [], 'employments'));

        return [
            'contact' => $contact,
            'library' => [
                'employments' => $employments,
                'standaloneProjects' => array_map($withClaims, $keep($library['standaloneProjects'] ?? [], 'projects')),
                'education' => $keep($library['education'] ?? [], 'credentials'),
                'certifications' => $keep($library['certifications'] ?? [], 'credentials'),
                'skills' => $keep($library['skills'] ?? [], 'skills'),
                'languages' => $keep($library['languages'] ?? [], 'languages'),
                'links' => $keep($library['links'] ?? [], 'links'),
            ],
        ];
    }

    /**
     * Every ULID the document refers to, grouped by kind (as keys, for lookups).
     *
     * @param  array<string, mixed>  $document
     * @return array<string, array<string, true>>
     */
    private static function references(array $document): array
    {
        $refs = ['employments' => [], 'projects' => [], 'claims' => [], 'credentials' => [], 'skills' => [], 'languages' => [], 'links' => []];
        $addItem = function (array $item, string $key, string $kind) use (&$refs): void {
            $refs[$kind][(string) ($item[$key] ?? '')] = true;

            foreach ($item['bullets'] ?? [] as $bullet) {
                $refs['claims'][(string) ($bullet['claim'] ?? '')] = true;
            }
        };

        foreach ($document['experience'] ?? [] as $job) {
            $addItem($job, 'employment', 'employments');

            foreach ($job['projects'] ?? [] as $project) {
                $addItem($project, 'project', 'projects');
            }
        }

        foreach ($document['projects'] ?? [] as $project) {
            $addItem($project, 'project', 'projects');
        }

        foreach ($document['skills'] ?? [] as $group) {
            foreach ($group['items'] ?? [] as $ulid) {
                $refs['skills'][(string) $ulid] = true;
            }
        }

        foreach ([...($document['education'] ?? []), ...($document['certifications'] ?? [])] as $item) {
            $refs['credentials'][(string) ($item['credential'] ?? '')] = true;
        }

        foreach (['languages', 'links'] as $kind) {
            foreach ($document[$kind] ?? [] as $ulid) {
                $refs[$kind][(string) $ulid] = true;
            }
        }

        return $refs;
    }
}
