<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Auth;

use Magento\User\Model\Backend\Config\ObserverConfig;
use Magento\User\Model\ResourceModel\User as UserResource;
use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\Auth\PasswordExpiryCheck;

class PasswordExpiryCheckTest extends TestCase
{
    /**
     * With forced password change off, an expired password doesn't block sign-in.
     */
    public function testNotRequiredWhenChangeNotForced(): void
    {
        $observerConfig = $this->createMock(ObserverConfig::class);
        $observerConfig->method('isPasswordChangeForced')->willReturn(false);
        $userResource = $this->createMock(UserResource::class);
        $userResource->expects($this->never())->method('getLatestPassword');

        $this->assertFalse((new PasswordExpiryCheck($observerConfig, $userResource))->isChangeRequired(3));
    }

    /**
     * Forced change + expired latest password blocks sign-in.
     */
    public function testRequiredWhenForcedAndExpired(): void
    {
        $latest = ['last_updated' => 1000];
        $observerConfig = $this->createMock(ObserverConfig::class);
        $observerConfig->method('isPasswordChangeForced')->willReturn(true);
        $observerConfig->method('_isLatestPasswordExpired')->with($latest)->willReturn(true);
        $userResource = $this->createMock(UserResource::class);
        $userResource->method('getLatestPassword')->with(3)->willReturn($latest);

        $this->assertTrue((new PasswordExpiryCheck($observerConfig, $userResource))->isChangeRequired(3));
    }

    /**
     * No password history row means nothing to expire.
     */
    public function testNotRequiredWithoutPasswordHistory(): void
    {
        $observerConfig = $this->createMock(ObserverConfig::class);
        $observerConfig->method('isPasswordChangeForced')->willReturn(true);
        $userResource = $this->createMock(UserResource::class);
        $userResource->method('getLatestPassword')->willReturn(false);

        $this->assertFalse((new PasswordExpiryCheck($observerConfig, $userResource))->isChangeRequired(3));
    }
}
