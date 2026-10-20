<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Auth;

use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\Auth\LoginRateLimiter;

class LoginRateLimiterTest extends TestCase
{
    use InMemoryCounterTrait;

    /**
     * An IP is blocked after 5 failures and unblocked when the 15-minute window ends.
     */
    public function testBlocksAfterFiveFailuresUntilWindowEnds(): void
    {
        $this->now = 1_800_000_000 - (1_800_000_000 % 900);
        $limiter = new LoginRateLimiter($this->counter());

        for ($i = 0; $i < 4; $i++) {
            $limiter->registerFailure('203.0.113.7');
        }
        $this->assertFalse($limiter->isBlocked('203.0.113.7'));

        $limiter->registerFailure('203.0.113.7');
        $this->assertTrue($limiter->isBlocked('203.0.113.7'));
        $this->assertFalse($limiter->isBlocked('198.51.100.1'));

        $this->now += 900;
        $this->assertFalse($limiter->isBlocked('203.0.113.7'));
    }

    /**
     * A successful login clears the IP's failures.
     */
    public function testResetClearsFailures(): void
    {
        $limiter = new LoginRateLimiter($this->counter());

        for ($i = 0; $i < 5; $i++) {
            $limiter->registerFailure('203.0.113.7');
        }
        $limiter->reset('203.0.113.7');

        $this->assertFalse($limiter->isBlocked('203.0.113.7'));
    }
}
