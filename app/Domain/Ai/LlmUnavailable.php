<?php

namespace App\Domain\Ai;

use RuntimeException;

/**
 * The provider could not be reached, timed out, or kept failing after the bounded retries.
 */
class LlmUnavailable extends RuntimeException {}
