<?php

namespace App\Domain\Ai;

/**
 * Another call with the same idempotency key is still running. Treated like an unavailable
 * provider by callers: nothing was sent, and trying again later is safe.
 */
class GenerationInProgress extends LlmUnavailable {}
