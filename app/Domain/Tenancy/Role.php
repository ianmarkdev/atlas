<?php

namespace App\Domain\Tenancy;

enum Role: string
{
    case Candidate = 'candidate';
    case Va = 'va';
}
