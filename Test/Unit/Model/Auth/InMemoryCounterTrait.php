<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Auth;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Yu\McpServer\Model\Auth\FixedWindowCounter;

/**
 * A real FixedWindowCounter over an in-memory cache and a clock the test moves via $now.
 */
trait InMemoryCounterTrait
{
    /** @var int Current Unix time seen by the counter. */
    private int $now = 1_800_000_000;

    /** @var array<string, string> Cache contents by key. */
    private array $cacheStore = [];

    /**
     * Builds the counter.
     */
    private function counter(): FixedWindowCounter
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(fn (string $id) => $this->cacheStore[$id] ?? false);
        $cache->method('save')->willReturnCallback(function (string $data, string $id) {
            $this->cacheStore[$id] = $data;
            return true;
        });
        $cache->method('remove')->willReturnCallback(function (string $id) {
            unset($this->cacheStore[$id]);
            return true;
        });

        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturnCallback(fn () => $this->now);

        return new FixedWindowCounter($cache, $dateTime);
    }
}
