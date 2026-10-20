<?php

namespace App\Domain\Knowledge;

/**
 * Deliberately short list (roadmap risk: over-modelling). A metric is an achievement with a number.
 */
enum ClaimKind: string
{
    case Achievement = 'achievement';
    case Responsibility = 'responsibility';
    case Skill = 'skill';
    case Qualification = 'qualification';
    case Other = 'other';
}
