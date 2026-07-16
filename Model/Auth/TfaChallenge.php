<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Auth;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Short-lived, single-use "password accepted, awaiting 2FA code" state for the OAuth login
 * flow in Controller/Oauth/Authorize.php.
 *
 * The login form deliberately keeps no server-side session between requests, so the fact
 * that a user already passed the password step has to be carried by an opaque token in the
 * 2FA form instead. The token is bound server-side (cache-backed, like the rate limiters)
 * to the authenticated admin user id, expires quickly, allows a bounded number of wrong
 * codes, and is removed on redemption — possession of the token alone never grants access,
 * it only grants the right to attempt a TOTP code.
 */
class TfaChallenge
{
    private const CACHE_TAG = 'MCP_OAUTH_TFA';
    private const TTL_SECONDS = 300;
    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly Json $serializer
    ) {
    }

    /**
     * Issues a new challenge token for an admin who just passed the password step.
     */
    public function issue(int $adminUserId): string
    {
        $token = bin2hex(random_bytes(32));
        $this->save($token, ['admin_user_id' => $adminUserId, 'failures' => 0]);

        return $token;
    }

    /**
     * Resolves a challenge token back to the admin user id it was issued for.
     *
     * @return int|null null when the token is unknown, expired, or exhausted its attempts
     */
    public function getAdminUserId(string $token): ?int
    {
        $data = $this->load($token);

        return $data !== null ? (int)$data['admin_user_id'] : null;
    }

    /**
     * Records a wrong-code attempt; the token is invalidated once MAX_ATTEMPTS is reached.
     */
    public function registerFailure(string $token): void
    {
        $data = $this->load($token);
        if ($data === null) {
            return;
        }

        $data['failures']++;
        if ($data['failures'] >= self::MAX_ATTEMPTS) {
            $this->cache->remove($this->cacheId($token));

            return;
        }

        $this->save($token, $data);
    }

    /**
     * Consumes the token after a successful code verification — it can't be used again.
     */
    public function redeem(string $token): void
    {
        $this->cache->remove($this->cacheId($token));
    }

    /**
     * Loads and validates the stored challenge payload for a token.
     *
     * @return array{admin_user_id: int, failures: int}|null
     */
    private function load(string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        $cached = $this->cache->load($this->cacheId($token));
        if ($cached === false) {
            return null;
        }

        $data = $this->serializer->unserialize($cached);
        if (!is_array($data) || !isset($data['admin_user_id'], $data['failures'])) {
            return null;
        }

        return $data;
    }

    /**
     * Persists the challenge payload under the token's cache key.
     *
     * @param array{admin_user_id: int, failures: int} $data
     */
    private function save(string $token, array $data): void
    {
        $this->cache->save(
            $this->serializer->serialize($data),
            $this->cacheId($token),
            [self::CACHE_TAG],
            self::TTL_SECONDS
        );
    }

    /**
     * Cache key for a challenge token.
     */
    private function cacheId(string $token): string
    {
        return 'mcp_oauth_tfa_' . sha1($token);
    }
}
