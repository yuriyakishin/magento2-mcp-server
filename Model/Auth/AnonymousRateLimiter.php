<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Auth;

/**
 * Throttles anonymous POST /mcp requests by IP. Authenticated callers are exempt — they
 * already proved who they are, and their damage potential is bounded by ACL, not volume.
 * The limit exists because the public tools are a free, structured API over the catalog:
 * convenient for AI assistants, but equally convenient for scrapers hammering the DB.
 */
class AnonymousRateLimiter
{
    private const MAX_REQUESTS = 60;
    private const WINDOW_SECONDS = 60;

    public function __construct(private readonly FixedWindowCounter $counter)
    {
    }

    /**
     * Records one anonymous request for this IP and reports whether the IP is still within
     * the per-minute limit. Rejected requests count too, so hammering doesn't pay off.
     *
     * @param string $ipAddress Caller's IP address (see ClientIpResolver).
     */
    public function registerAndCheck(string $ipAddress): bool
    {
        return $this->counter->increment('anon|' . $ipAddress, self::WINDOW_SECONDS) <= self::MAX_REQUESTS;
    }

    /**
     * Seconds until the current window ends and the client may send requests again.
     */
    public function retryAfterSeconds(): int
    {
        return $this->counter->secondsUntilReset(self::WINDOW_SECONDS);
    }
}
