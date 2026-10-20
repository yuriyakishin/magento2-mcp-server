<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Auth;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Cache-backed request counter over fixed time windows (e.g. 12:00:00-12:00:59), shared by
 * the module's rate limiters.
 *
 * Each window has its own cache entry, so a counter always starts from zero when its window
 * ends. Re-saving one entry with a fresh TTL on every hit would instead keep it alive as long
 * as requests arrive more often than the TTL — a slow but steady client would then pile up
 * hits indefinitely and end up blocked for good.
 */
class FixedWindowCounter
{
    private const CACHE_TAG = 'MCP_RATE_LIMIT';

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * Records one hit and returns the bucket's count in the current window, this hit included.
     *
     * @param string $bucket Counter identity, e.g. "login|203.0.113.7".
     * @param int $windowSeconds Window length in seconds.
     */
    public function increment(string $bucket, int $windowSeconds): int
    {
        $count = $this->current($bucket, $windowSeconds) + 1;
        $this->cache->save(
            (string)$count,
            $this->cacheId($bucket, $windowSeconds),
            [self::CACHE_TAG],
            $windowSeconds
        );

        return $count;
    }

    /**
     * The bucket's count in the current window.
     *
     * @param string $bucket Counter identity.
     * @param int $windowSeconds Window length in seconds.
     */
    public function current(string $bucket, int $windowSeconds): int
    {
        $cached = $this->cache->load($this->cacheId($bucket, $windowSeconds));

        return $cached !== false ? (int)$cached : 0;
    }

    /**
     * Clears the bucket's count in the current window.
     *
     * @param string $bucket Counter identity.
     * @param int $windowSeconds Window length in seconds.
     */
    public function reset(string $bucket, int $windowSeconds): void
    {
        $this->cache->remove($this->cacheId($bucket, $windowSeconds));
    }

    /**
     * Seconds until the current window ends and every counter starts over.
     *
     * @param int $windowSeconds Window length in seconds.
     */
    public function secondsUntilReset(int $windowSeconds): int
    {
        return $windowSeconds - ($this->dateTime->gmtTimestamp() % $windowSeconds);
    }

    /**
     * Cache key of the bucket's entry for the current window.
     *
     * @param string $bucket Counter identity.
     * @param int $windowSeconds Window length in seconds.
     */
    private function cacheId(string $bucket, int $windowSeconds): string
    {
        $window = intdiv($this->dateTime->gmtTimestamp(), $windowSeconds);

        return 'mcp_rate_limit_' . sha1($bucket) . '_' . $windowSeconds . '_' . $window;
    }
}
