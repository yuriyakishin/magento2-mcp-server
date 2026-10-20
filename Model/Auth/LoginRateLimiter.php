<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Auth;

/**
 * Throttles repeated failed login attempts against Controller/Oauth/Authorize.php by IP.
 *
 * This works alongside Magento's own per-account lockout (see etc/frontend/events.xml):
 * /mcp/oauth/authorize is an unauthenticated attack surface reachable by anyone, and an IP
 * limit also slows down guessing spread across many accounts.
 */
class LoginRateLimiter
{
    private const MAX_ATTEMPTS = 5;
    private const WINDOW_SECONDS = 900;

    public function __construct(private readonly FixedWindowCounter $counter)
    {
    }

    /**
     * Whether this IP has used up its failed attempts for the current window.
     *
     * @param string $ipAddress Caller's IP address.
     */
    public function isBlocked(string $ipAddress): bool
    {
        return $this->counter->current($this->bucket($ipAddress), self::WINDOW_SECONDS) >= self::MAX_ATTEMPTS;
    }

    /**
     * Records one failed login attempt for this IP.
     *
     * @param string $ipAddress Caller's IP address.
     */
    public function registerFailure(string $ipAddress): void
    {
        $this->counter->increment($this->bucket($ipAddress), self::WINDOW_SECONDS);
    }

    /**
     * Clears the failure count for this IP after a successful login.
     *
     * @param string $ipAddress Caller's IP address.
     */
    public function reset(string $ipAddress): void
    {
        $this->counter->reset($this->bucket($ipAddress), self::WINDOW_SECONDS);
    }

    /**
     * Counter bucket for this IP.
     *
     * @param string $ipAddress Caller's IP address.
     */
    private function bucket(string $ipAddress): string
    {
        return 'login|' . $ipAddress;
    }
}
