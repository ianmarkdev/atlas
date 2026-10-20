<?php

namespace App\Domain\Knowledge;

/**
 * Only the candidate approves answers. A VA's new answer is a draft until then.
 */
enum AnswerStatus: string
{
    case Approved = 'approved';
    case Draft = 'draft';
    case Archived = 'archived';
}
