<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Auth;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\Auth\TfaChallenge;

class TfaChallengeTest extends TestCase
{
    /**
     * Builds the subject with a real Json serializer (it has no dependencies).
     */
    private function createChallenge(CacheInterface $cache): TfaChallenge
    {
        return new TfaChallenge($cache, new Json());
    }

    /**
     * Issuing a challenge must save the admin user id under a fresh token and return it.
     */
    public function testIssueSavesAdminUserIdAndReturnsToken(): void
    {
        $savedPayload = null;
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())
            ->method('save')
            ->with(
                $this->callback(function (string $payload) use (&$savedPayload): bool {
                    $savedPayload = json_decode($payload, true);

                    return true;
                }),
                $this->isType('string'),
                $this->isType('array'),
                $this->isType('int')
            );

        $token = $this->createChallenge($cache)->issue(42);

        $this->assertSame(64, strlen($token));
        $this->assertSame(['admin_user_id' => 42, 'failures' => 0], $savedPayload);
    }

    /**
     * A stored token must resolve back to the admin user id it was issued for.
     */
    public function testGetAdminUserIdResolvesStoredToken(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn('{"admin_user_id":42,"failures":1}');

        $this->assertSame(42, $this->createChallenge($cache)->getAdminUserId('sometoken'));
    }

    /**
     * An unknown or expired token must resolve to null.
     */
    public function testGetAdminUserIdReturnsNullForUnknownToken(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn(false);

        $this->assertNull($this->createChallenge($cache)->getAdminUserId('sometoken'));
    }

    /**
     * An empty token must resolve to null without touching the cache.
     */
    public function testGetAdminUserIdReturnsNullForEmptyToken(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->never())->method('load');

        $this->assertNull($this->createChallenge($cache)->getAdminUserId(''));
    }

    /**
     * A wrong-code attempt below the limit must re-save the payload with an incremented
     * failure count.
     */
    public function testRegisterFailureIncrementsCounter(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn('{"admin_user_id":42,"failures":1}');
        $cache->expects($this->once())
            ->method('save')
            ->with('{"admin_user_id":42,"failures":2}');
        $cache->expects($this->never())->method('remove');

        $this->createChallenge($cache)->registerFailure('sometoken');
    }

    /**
     * Reaching the attempt limit must invalidate the token instead of re-saving it.
     */
    public function testRegisterFailureInvalidatesTokenAtLimit(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn('{"admin_user_id":42,"failures":4}');
        $cache->expects($this->once())->method('remove');
        $cache->expects($this->never())->method('save');

        $this->createChallenge($cache)->registerFailure('sometoken');
    }

    /**
     * Redeeming a token must remove it so it can't be used again.
     */
    public function testRedeemRemovesToken(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())->method('remove')->with($this->isType('string'));

        $this->createChallenge($cache)->redeem('sometoken');
    }
}
