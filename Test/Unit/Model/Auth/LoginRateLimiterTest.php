<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Auth;

use Magento\Framework\App\CacheInterface;
use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\Auth\LoginRateLimiter;

class LoginRateLimiterTest extends TestCase
{
    /**
     * An IP with no recorded failures must not be blocked.
     */
    public function testNotBlockedWithoutFailures(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn(false);

        $rateLimiter = new LoginRateLimiter($cache);

        $this->assertFalse($rateLimiter->isBlocked('127.0.0.1'));
    }

    /**
     * An IP at or above the failure threshold must be blocked.
     */
    public function testBlockedAtFailureThreshold(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn('5');

        $rateLimiter = new LoginRateLimiter($cache);

        $this->assertTrue($rateLimiter->isBlocked('127.0.0.1'));
    }

    /**
     * Registering a failure must save an incremented counter under the IP's cache key.
     */
    public function testRegisterFailureIncrementsStoredCount(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn('2');
        $cache->expects($this->once())
            ->method('save')
            ->with('3', $this->isType('string'), $this->isType('array'), $this->isType('int'));

        $rateLimiter = new LoginRateLimiter($cache);

        $rateLimiter->registerFailure('127.0.0.1');
    }

    /**
     * Resetting an IP must remove its cache entry.
     */
    public function testResetRemovesCacheEntry(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())->method('remove')->with($this->isType('string'));

        $rateLimiter = new LoginRateLimiter($cache);

        $rateLimiter->reset('127.0.0.1');
    }
}
