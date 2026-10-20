<?php

namespace App\Domain\Knowledge;

enum CredentialType: string
{
    case Education = 'education';
    case Certification = 'certification';
}
