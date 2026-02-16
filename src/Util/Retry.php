<?php

declare(strict_types=1);

namespace BengalStudio\AI\Util;

use BengalStudio\AI\Exceptions\RetryException;

/**
 * Retry mechanism for API calls.
 */
class Retry
{
    /**
     * Execute a callable with retries.
     *
     * @template T
     * @param callable(): T $fn The function to retry.
     * @param int $maxRetries Maximum number of retries (default 2).
     * @param int $initialDelayMs Initial delay in milliseconds (default 500).
     * @param float $backoffFactor Exponential backoff factor (default 2.0).
     * @return T
     * @throws RetryException
     */
    public static function execute(
        callable $fn,
        int $maxRetries = 2,
        int $initialDelayMs = 500,
        float $backoffFactor = 2.0,
    ): mixed {
        $errors = [];
        $attempts = 0;

        while ($attempts <= $maxRetries) {
            try {
                return $fn();
            } catch (\Throwable $e) {
                $errors[] = $e;
                $attempts++;

                if ($attempts > $maxRetries) {
                    throw new RetryException(
                        maxRetries: $maxRetries,
                        errors: $errors,
                        previous: $e,
                    );
                }

                $delayMs = (int) ($initialDelayMs * pow($backoffFactor, $attempts - 1));
                usleep($delayMs * 1000);
            }
        }

        // Should never reach here, but make static analysis happy
        throw new RetryException(maxRetries: $maxRetries, errors: $errors);
    }
}
