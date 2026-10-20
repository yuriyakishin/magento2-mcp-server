<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Auth;

/**
 * Per-IP throttling of anonymous OAuth client registrations (POST /mcp/oauth/register),
 * so the open endpoint can't be used to flood mcp_oauth_client.
 */
class RegistrationRateLimiter
{
    private const MAX_REGISTRATIONS = 20;
    private const WINDOW_SECONDS = 3600;

    public function __construct(private readonly FixedWindowCounter $counter)
    {
    }

    /**
     * Counts this registration attempt and reports whether it is still within the limit.
     *
     * @param string $ipAddress Client IP address.
     */
    public function registerAndCheck(string $ipAddress): bool
    {
        return $this->counter->increment('register|' . $ipAddress, self::WINDOW_SECONDS) <= self::MAX_REGISTRATIONS;
    }
}
