<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Auth;

use Magento\Framework\App\CacheInterface;
use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\Auth\AnonymousRateLimiter;

class AnonymousRateLimiterTest extends TestCase
{
    /**
     * Builds a limiter over an in-memory fake of the cache backend.
     */
    private function makeLimiter(): AnonymousRateLimiter
    {
        $storage = [];

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(
            static function (string $id) use (&$storage) {
                return $storage[$id] ?? false;
            }
        );
        $cache->method('save')->willReturnCallback(
            static function (string $data, string $id) use (&$storage) {
                $storage[$id] = $data;
                return true;
            }
        );

        return new AnonymousRateLimiter($cache);
    }

    /**
     * Requests under the per-minute limit pass; the request over it is rejected.
     */
    public function testBlocksRequestsOverTheLimit(): void
    {
        $limiter = $this->makeLimiter();

        for ($i = 1; $i <= 60; $i++) {
            $this->assertTrue($limiter->registerAndCheck('10.0.0.1'), "request #$i should pass");
        }

        $this->assertFalse($limiter->registerAndCheck('10.0.0.1'), 'request #61 should be blocked');
    }

    /**
     * The counters are per IP — one noisy client must not block another.
     */
    public function testLimitIsPerIp(): void
    {
        $limiter = $this->makeLimiter();

        for ($i = 1; $i <= 61; $i++) {
            $limiter->registerAndCheck('10.0.0.1');
        }

        $this->assertTrue($limiter->registerAndCheck('10.0.0.2'));
    }

    /**
     * Retry-After advertises the full window as a safe upper bound.
     */
    public function testRetryAfterIsTheWindow(): void
    {
        $this->assertSame(60, $this->makeLimiter()->retryAfterSeconds());
    }
}
