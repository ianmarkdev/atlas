<?php

namespace App\Domain\Retrieval;

/**
 * What to look for and where. The workspace comes from the tenant context; the profile is
 * always required (no cross-profile retrieval).
 */
final readonly class RetrievalQuery
{
    public const UNIT_TYPES = ['claim', 'library_answer', 'project_summary', 'employment_summary'];

    /**
     * @param  list<string>  $unitTypes
     * @param  list<string>  $statuses
     * @param  list<int>|null  $claimIds  only these bullet points (a resume's); null = the whole library
     */
    public function __construct(
        public string $text,
        public int $candidateProfileId,
        public int $limit = 8,
        public array $unitTypes = self::UNIT_TYPES,
        public array $statuses = RetrievalText::ACTIVE_STATUSES,
        public ?array $claimIds = null,
    ) {}
}
