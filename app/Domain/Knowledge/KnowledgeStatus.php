<?php

namespace App\Domain\Knowledge;

/**
 * Lifecycle of employments and projects. Only the candidate moves a record to `Verified`.
 */
enum KnowledgeStatus: string
{
    case Verified = 'verified';
    case Proposed = 'proposed';
    case Archived = 'archived';
}
