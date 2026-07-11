<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Auth;

use Magento\Framework\App\CacheInterface;

/**
 * Throttles anonymous POST /mcp requests by IP. Authenticated callers are exempt — they
 * already proved who they are, and their damage potential is bounded by ACL, not volume.
 * The limit exists because the public tools are a free, structured API over the catalog:
 * convenient for AI assistants, but equally convenient for scrapers hammering the DB.
 */
class AnonymousRateLimiter
{
    private const CACHE_TAG = 'MCP_ANON_CALLS';
    private const MAX_REQUESTS = 60;
    private const WINDOW_SECONDS = 60;

    public function __construct(private readonly CacheInterface $cache)
    {
    }

    /**
     * Records one anonymous request for this IP and reports whether the IP is now over
     * the per-minute limit. Counting before answering means a blocked client cannot
     * keep the window fresh for free — it has to actually back off.
     */
    public function registerAndCheck(string $ipAddress): bool
    {
        $requests = $this->getRequests($ipAddress) + 1;
        $this->cache->save((string)$requests, $this->cacheId($ipAddress), [self::CACHE_TAG], self::WINDOW_SECONDS);

        return $requests <= self::MAX_REQUESTS;
    }

    /**
     * Seconds the client should wait before retrying (the full window — the cache backend
     * doesn't expose the counter's remaining TTL, and a whole window is a safe upper bound).
     */
    public function retryAfterSeconds(): int
    {
        return self::WINDOW_SECONDS;
    }

    /**
     * Current number of recent anonymous requests recorded for this IP.
     */
    private function getRequests(string $ipAddress): int
    {
        $cached = $this->cache->load($this->cacheId($ipAddress));

        return $cached !== false ? (int)$cached : 0;
    }

    /**
     * Cache key for this IP's request counter.
     */
    private function cacheId(string $ipAddress): string
    {
        return 'mcp_anon_calls_' . sha1($ipAddress);
    }
}
