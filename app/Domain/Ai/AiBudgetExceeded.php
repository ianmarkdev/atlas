<?php

namespace App\Domain\Ai;

use RuntimeException;

/**
 * A paid call would take this month's AI spending over the workspace budget.
 */
class AiBudgetExceeded extends RuntimeException {}
