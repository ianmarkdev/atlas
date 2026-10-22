<?php

namespace App\Domain\Applications;

/**
 * Finds an application already saved for the same job listing, by source URL (see SourceUrl).
 * Deleted applications count too, so a listing you dismissed is not silently saved again.
 *
 * URLs are compared in PHP: a workspace holds a few hundred applications at most (D1).
 */
class DuplicateApplicationDetector
{
    public function forSourceUrl(string $sourceUrl): ?JobApplication
    {
        $key = SourceUrl::key($sourceUrl);

        if ($key === null) {
            return null;
        }

        return JobApplication::withTrashed()
            ->whereNotNull('source_url')
            ->orderByRaw('deleted_at IS NOT NULL')
            ->oldest('id')
            ->get(['id', 'ulid', 'workspace_id', 'company_name', 'role_title', 'source_url', 'stage', 'deleted_at'])
            ->first(fn (JobApplication $application): bool => SourceUrl::key((string) $application->source_url) === $key);
    }
}
