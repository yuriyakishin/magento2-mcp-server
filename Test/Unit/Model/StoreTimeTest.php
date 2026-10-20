<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model;

use PHPUnit\Framework\TestCase;

class StoreTimeTest extends TestCase
{
    use StoreTimeMockTrait;

    /**
     * A store-local day boundary becomes the matching UTC instant, DST included.
     */
    public function testToUtcConvertsFromStoreTimezone(): void
    {
        $storeTime = $this->storeTime('America/Chicago');

        $this->assertSame('2026-07-01 05:00:00', $storeTime->toUtc('2026-07-01'));
        $this->assertSame('2026-01-01 06:00:00', $storeTime->toUtc('2026-01-01 00:00:00'));
        $this->assertSame('2026-03-01 21:59:59', $this->storeTime('Europe/Kyiv')->toUtc('2026-03-01 23:59:59'));
    }

    /**
     * UTC timestamps from the DB come back in store-local time; empty values pass through.
     */
    public function testToLocalConvertsToStoreTimezone(): void
    {
        $storeTime = $this->storeTime('America/Chicago');

        $this->assertSame('2026-07-08 04:15:00', $storeTime->toLocal('2026-07-08 09:15:00'));
        $this->assertNull($storeTime->toLocal(null));
        $this->assertSame('', $storeTime->toLocal(''));
    }

    /**
     * The local calendar date of an instant follows the store's day, not UTC's.
     */
    public function testLocalDateUsesStoreDay(): void
    {
        $timestamp = strtotime('2026-07-08 03:00:00 UTC');

        $this->assertSame('2026-07-07', $this->storeTime('America/Chicago')->localDate($timestamp));
        $this->assertSame('2026-07-08', $this->storeTime('UTC')->localDate($timestamp));
    }

    /**
     * A missing timezone setting falls back to UTC.
     */
    public function testEmptyConfigFallsBackToUtc(): void
    {
        $this->assertSame('UTC', $this->storeTime('')->getTimezone());
    }
}
