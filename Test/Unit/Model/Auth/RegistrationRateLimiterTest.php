<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Auth;

use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\Auth\RegistrationRateLimiter;

class RegistrationRateLimiterTest extends TestCase
{
    use InMemoryCounterTrait;

    /**
     * 20 registrations per hour pass, the 21st is rejected.
     */
    public function testLimitsRegistrationsPerHour(): void
    {
        $this->now = 1_800_000_000 - (1_800_000_000 % 3600);
        $limiter = new RegistrationRateLimiter($this->counter());

        for ($i = 0; $i < 20; $i++) {
            $this->assertTrue($limiter->registerAndCheck('203.0.113.7'));
        }
        $this->assertFalse($limiter->registerAndCheck('203.0.113.7'));

        $this->now += 3600;
        $this->assertTrue($limiter->registerAndCheck('203.0.113.7'));
    }
}
