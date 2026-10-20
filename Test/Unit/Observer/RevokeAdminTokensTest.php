<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Observer;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\User\Model\User;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\ResourceModel\Oauth\Token as TokenResource;
use Yu\McpServer\Observer\RevokeAdminTokens;

class RevokeAdminTokensTest extends TestCase
{
    /**
     * A password change revokes all of the admin's tokens.
     */
    public function testRevokesOnPasswordChange(): void
    {
        $tokenResource = $this->createMock(TokenResource::class);
        $tokenResource->expects($this->once())
            ->method('revokeAllForAdmin')
            ->with(7, '2026-01-01 00:00:00');

        $this->observer($tokenResource)->execute($this->event($this->user(7, true, 1)));
    }

    /**
     * Deactivating the admin revokes all of their tokens.
     */
    public function testRevokesOnDeactivation(): void
    {
        $tokenResource = $this->createMock(TokenResource::class);
        $tokenResource->expects($this->once())->method('revokeAllForAdmin');

        $this->observer($tokenResource)->execute($this->event($this->user(7, false, 0)));
    }

    /**
     * Saving an active admin without a password change leaves tokens alone.
     */
    public function testIgnoresUnrelatedSave(): void
    {
        $tokenResource = $this->createMock(TokenResource::class);
        $tokenResource->expects($this->never())->method('revokeAllForAdmin');

        $this->observer($tokenResource)->execute($this->event($this->user(7, false, 1)));
    }

    /**
     * @param TokenResource&MockObject $tokenResource Token resource mock.
     */
    private function observer(TokenResource&MockObject $tokenResource): RevokeAdminTokens
    {
        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(strtotime('2026-01-01 00:00:00 UTC'));

        return new RevokeAdminTokens($tokenResource, $dateTime);
    }

    /**
     * Admin user mock with the fields the observer inspects.
     */
    private function user(int $id, bool $passwordChanged, int $isActive): User&MockObject
    {
        $user = $this->getMockBuilder(User::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getPassword', 'dataHasChangedFor', 'getIsActive'])
            ->getMock();
        $user->method('getId')->willReturn($id);
        $user->method('getPassword')->willReturn('hash');
        $user->method('dataHasChangedFor')->with('password')->willReturn($passwordChanged);
        $user->method('getIsActive')->willReturn($isActive);

        return $user;
    }

    /**
     * @param User $user Saved admin user carried by the event.
     */
    private function event(User $user): Observer
    {
        return new Observer(['event' => new Event(['object' => $user])]);
    }
}
