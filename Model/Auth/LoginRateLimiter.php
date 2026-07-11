<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Auth;

use Magento\Framework\App\CacheInterface;

/**
 * Throttles repeated failed login attempts against Controller/Oauth/Authorize.php by IP.
 *
 * This is deliberately separate from whatever lockout Magento's own admin login already
 * applies — /mcp/oauth/authorize is a new, unauthenticated attack surface reachable by
 * anyone, and needs its own guard rather than relying solely on Auth::login()'s protections.
 */
class LoginRateLimiter
{
    private const CACHE_TAG = 'MCP_OAUTH_LOGIN';
    private const MAX_ATTEMPTS = 5;
    private const WINDOW_SECONDS = 900;

    public function __construct(private readonly CacheInterface $cache)
    {
    }

    /**
     * Whether this IP has exceeded the allowed number of recent failed attempts.
     */
    public function isBlocked(string $ipAddress): bool
    {
        return $this->getAttempts($ipAddress) >= self::MAX_ATTEMPTS;
    }

    /**
     * Records a failed login attempt for this IP.
     */
    public function registerFailure(string $ipAddress): void
    {
        $attempts = $this->getAttempts($ipAddress) + 1;
        $this->cache->save((string) $attempts, $this->cacheId($ipAddress), [self::CACHE_TAG], self::WINDOW_SECONDS);
    }

    /**
     * Clears the failure count for this IP after a successful login.
     */
    public function reset(string $ipAddress): void
    {
        $this->cache->remove($this->cacheId($ipAddress));
    }

    /**
     * Current number of recent failed attempts recorded for this IP.
     */
    private function getAttempts(string $ipAddress): int
    {
        $cached = $this->cache->load($this->cacheId($ipAddress));

        return $cached !== false ? (int) $cached : 0;
    }

    /**
     * Cache key for this IP's failure counter.
     */
    private function cacheId(string $ipAddress): string
    {
        return 'mcp_oauth_login_' . sha1($ipAddress);
    }
}
