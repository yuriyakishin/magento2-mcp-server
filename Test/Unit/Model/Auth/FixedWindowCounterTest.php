<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Auth;

use PHPUnit\Framework\TestCase;

class FixedWindowCounterTest extends TestCase
{
    use InMemoryCounterTrait;

    /**
     * Hits accumulate within one window and start over in the next one.
     */
    public function testCountsPerWindow(): void
    {
        $this->now = 1_800_000_000 - (1_800_000_000 % 60);
        $counter = $this->counter();

        $this->assertSame(1, $counter->increment('a', 60));
        $this->assertSame(2, $counter->increment('a', 60));
        $this->assertSame(1, $counter->increment('b', 60));

        $this->now += 60;
        $this->assertSame(0, $counter->current('a', 60));
        $this->assertSame(1, $counter->increment('a', 60));
    }

    /**
     * A steady client below the rate never accumulates across windows — the bug the
     * fixed windows replace: one TTL refreshed on every hit kept the count alive forever.
     */
    public function testSteadySlowClientNeverAccumulates(): void
    {
        $counter = $this->counter();

        $max = 0;
        for ($i = 0; $i < 200; $i++) {
            $max = max($max, $counter->increment('slow', 60));
            $this->now += 30;
        }

        $this->assertLessThanOrEqual(2, $max);
    }

    /**
     * Reset clears the current window; secondsUntilReset counts down to its end.
     */
    public function testResetAndSecondsUntilReset(): void
    {
        $this->now = 1_800_000_000 - (1_800_000_000 % 60) + 45;
        $counter = $this->counter();

        $counter->increment('a', 60);
        $counter->reset('a', 60);

        $this->assertSame(0, $counter->current('a', 60));
        $this->assertSame(15, $counter->secondsUntilReset(60));
    }
}
