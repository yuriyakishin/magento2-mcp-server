<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model;

use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Yu\McpServer\Model\StoreTime;

/**
 * Builds a real StoreTime over a mocked store timezone, for tool tests. The UTC default
 * makes conversions identity, so fixtures can keep plain timestamps.
 */
trait StoreTimeMockTrait
{
    /**
     * @param string $timezone Store timezone identifier.
     */
    private function storeTime(string $timezone = 'UTC'): StoreTime
    {
        $timezoneMock = $this->createMock(TimezoneInterface::class);
        $timezoneMock->method('getConfigTimezone')->willReturn($timezone);

        return new StoreTime($timezoneMock);
    }
}
