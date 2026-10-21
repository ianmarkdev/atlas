<?php

namespace App\Domain\Ai;

use RuntimeException;

/**
 * The selected provider is not usable as configured (no API key, external providers not
 * allowed, unknown task or provider). Raised before anything is sent.
 */
class LlmConfigurationError extends RuntimeException {}
