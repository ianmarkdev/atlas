<?php

namespace App\Domain\Ai\Clients;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Sleep;

/**
 * Bounded retries for provider HTTP calls: connection errors, 408, 429, 5xx and 529
 * (overloaded) are retried with exponential backoff and jitter, honouring `Retry-After`.
 */
class ProviderRetry
{
    private const RETRYABLE = [408, 409, 429, 500, 502, 503, 504, 529];

    /**
     * @param  Closure(): Response  $send
     * @return Response the last response (successful or not, the caller decides)
     *
     * @throws ConnectionException when the provider could not be reached on the last attempt
     */
    public static function run(Closure $send): Response
    {
        $maxAttempts = max(1, (int) config('ai.retries.max_attempts'));

        for ($attempt = 1; ; $attempt++) {
            try {
                $response = $send();
            } catch (ConnectionException $exception) {
                if ($attempt >= $maxAttempts) {
                    throw $exception;
                }

                self::wait($attempt, null);

                continue;
            }

            if (! in_array($response->status(), self::RETRYABLE, true) || $attempt >= $maxAttempts) {
                return $response;
            }

            self::wait($attempt, $response->header('retry-after'));
        }
    }

    private static function wait(int $attempt, ?string $retryAfter): void
    {
        $base = (int) config('ai.retries.base_delay_ms');
        $max = (int) config('ai.retries.max_delay_ms');

        $delay = is_numeric($retryAfter)
            ? (int) ((float) $retryAfter * 1000)
            : $base * (2 ** ($attempt - 1)) + random_int(0, max(0, $base));

        Sleep::for(min($max, max(0, $delay)))->milliseconds();
    }
}
