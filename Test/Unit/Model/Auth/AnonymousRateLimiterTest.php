<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Auth;

use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\Auth\AnonymousRateLimiter;

class AnonymousRateLimiterTest extends TestCase
{
    use InMemoryCounterTrait;

    /**
     * 60 requests per minute pass, the 61st is rejected, and the next minute starts over.
     */
    public function testLimitsPerMinuteAndRecovers(): void
    {
        $this->now = 1_800_000_000 - (1_800_000_000 % 60);
        $limiter = new AnonymousRateLimiter($this->counter());

        for ($i = 0; $i < 60; $i++) {
            $this->assertTrue($limiter->registerAndCheck('203.0.113.7'));
        }
        $this->assertFalse($limiter->registerAndCheck('203.0.113.7'));
        $this->assertTrue($limiter->registerAndCheck('198.51.100.1'));

        $this->now += 60;
        $this->assertTrue($limiter->registerAndCheck('203.0.113.7'));
    }

    /**
     * Retry-After points at the end of the current window, not a full window ahead.
     */
    public function testRetryAfterIsTimeLeftInWindow(): void
    {
        $this->now = 1_800_000_000 - (1_800_000_000 % 60) + 50;

        $this->assertSame(10, (new AnonymousRateLimiter($this->counter()))->retryAfterSeconds());
    }
}
