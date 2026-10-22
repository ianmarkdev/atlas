<?php

namespace App\Domain\Applications;

use RuntimeException;

/**
 * The listing being captured is already saved as an application.
 */
class DuplicateApplication extends RuntimeException
{
    public function __construct(public readonly JobApplication $existing)
    {
        parent::__construct('This job listing has already been saved.');
    }
}
