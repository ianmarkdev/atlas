<?php

namespace App\Domain\Knowledge;

enum LinkKind: string
{
    case Portfolio = 'portfolio';
    case Github = 'github';
    case Linkedin = 'linkedin';
    case Website = 'website';
    case Other = 'other';
}
