<?php

declare(strict_types=1);

namespace BengalStudio\AI\Tests\Util;

use BengalStudio\AI\Exceptions\RetryException;
use BengalStudio\AI\Util\Retry;
use PHPUnit\Framework\TestCase;

class RetryTest extends TestCase
{
    public function testSuccessOnFirstAttempt(): void
    {
        $result = Retry::execute(fn() => 42, maxRetries: 2, initialDelayMs: 0);
        $this->assertSame(42, $result);
    }

    public function testRetriesOnFailureThenSucceeds(): void
    {
        $attempts = 0;
        $result = Retry::execute(
            function () use (&$attempts) {
                $attempts++;
                if ($attempts < 3) {
                    throw new \RuntimeException("Attempt $attempts failed");
                }
                return 'ok';
            },
            maxRetries: 3,
            initialDelayMs: 1, // minimal delay for test speed
        );

        $this->assertSame('ok', $result);
        $this->assertSame(3, $attempts);
    }

    public function testThrowsRetryExceptionAfterMaxRetries(): void
    {
        $this->expectException(RetryException::class);
        $this->expectExceptionMessage('Maximum retries (2) exceeded');

        Retry::execute(
            fn() => throw new \RuntimeException('always fail'),
            maxRetries: 2,
            initialDelayMs: 1,
        );
    }

    public function testRetryExceptionContainsErrors(): void
    {
        try {
            Retry::execute(
                fn() => throw new \RuntimeException('fail'),
                maxRetries: 1,
                initialDelayMs: 1,
            );
            $this->fail('Expected RetryException');
        } catch (RetryException $e) {
            $this->assertSame(1, $e->maxRetries);
            // 1 initial + 1 retry = 2 errors
            $this->assertCount(2, $e->errors);
        }
    }

    public function testZeroRetriesMeansOneAttempt(): void
    {
        $attempts = 0;

        try {
            Retry::execute(
                function () use (&$attempts) {
                    $attempts++;
                    throw new \RuntimeException('fail');
                },
                maxRetries: 0,
                initialDelayMs: 1,
            );
        } catch (RetryException $e) {
            // expected
        }

        $this->assertSame(1, $attempts);
    }
}
