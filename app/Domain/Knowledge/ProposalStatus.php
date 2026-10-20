<?php

namespace App\Domain\Knowledge;

enum ProposalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    /** A newer suggestion for the same item replaced this one. */
    case Superseded = 'superseded';
    /** The item changed after the suggestion was made, so it could not be applied safely. */
    case Conflict = 'conflict';
}
