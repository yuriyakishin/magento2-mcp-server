<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Auth;

use Magento\Authorization\Model\Acl\AclRetriever;
use Magento\Authorization\Model\ResourceModel\Role\Collection as RoleCollection;
use Magento\Authorization\Model\ResourceModel\Role\CollectionFactory as RoleCollectionFactory;
use Magento\Authorization\Model\Role;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Yu\McpServer\Exception\UnauthorizedException;
use Yu\McpServer\Model\Auth\AdminTokenValidator;
use Yu\McpServer\Model\Oauth\Token;
use Yu\McpServer\Model\Oauth\TokenRepository;

class AdminTokenValidatorTest extends TestCase
{
    /**
     * An unknown access token must fail as Unauthorized.
     */
    public function testThrowsForUnknownToken(): void
    {
        $tokenRepository = $this->createMock(TokenRepository::class);
        $tokenRepository->method('getByAccessToken')->willThrowException(
            new NoSuchEntityException(__('not found'))
        );

        $validator = new AdminTokenValidator(
            $tokenRepository,
            $this->createMock(AclRetriever::class),
            $this->createMock(RoleCollectionFactory::class),
            $this->createMock(DateTime::class)
        );

        $this->expectException(UnauthorizedException::class);

        $validator->validate('unknown-token');
    }

    /**
     * A revoked token must fail as Unauthorized even if it hasn't expired yet.
     */
    public function testThrowsForRevokedToken(): void
    {
        $token = $this->mockToken(['getRevokedAt' => '2026-01-01 00:00:00']);

        $tokenRepository = $this->createMock(TokenRepository::class);
        $tokenRepository->method('getByAccessToken')->willReturn($token);

        $validator = new AdminTokenValidator(
            $tokenRepository,
            $this->createMock(AclRetriever::class),
            $this->createMock(RoleCollectionFactory::class),
            $this->createMock(DateTime::class)
        );

        $this->expectException(UnauthorizedException::class);

        $validator->validate('revoked-token');
    }

    /**
     * An expired token must fail as Unauthorized.
     */
    public function testThrowsForExpiredToken(): void
    {
        $token = $this->mockToken(['getRevokedAt' => null, 'getExpiresAt' => '2020-01-01 00:00:00']);

        $tokenRepository = $this->createMock(TokenRepository::class);
        $tokenRepository->method('getByAccessToken')->willReturn($token);

        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(strtotime('2026-01-01 00:00:00'));

        $validator = new AdminTokenValidator(
            $tokenRepository,
            $this->createMock(AclRetriever::class),
            $this->createMock(RoleCollectionFactory::class),
            $dateTime
        );

        $this->expectException(UnauthorizedException::class);

        $validator->validate('expired-token');
    }

    /**
     * A valid token must resolve to an AdminContext whose ACL resources are read from the
     * admin's parent group role — not via getAllowedResourcesByUser(), which reads the
     * personal U-type role and always comes back empty for admin users.
     */
    public function testResolvesValidTokenToAdminContext(): void
    {
        $token = $this->mockToken([
            'getRevokedAt' => null,
            'getExpiresAt' => '2030-01-01 00:00:00',
            'getAdminUserId' => 3,
        ]);

        $tokenRepository = $this->createMock(TokenRepository::class);
        $tokenRepository->method('getByAccessToken')->willReturn($token);

        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(strtotime('2026-01-01 00:00:00'));

        $aclRetriever = $this->createMock(AclRetriever::class);
        $aclRetriever->expects($this->once())
            ->method('getAllowedResourcesByRole')
            ->with(1)
            ->willReturn(['Magento_Sales::sales']);
        $aclRetriever->expects($this->never())->method('getAllowedResourcesByUser');

        $validator = new AdminTokenValidator(
            $tokenRepository,
            $aclRetriever,
            $this->mockRoleCollectionFactory(3, userRoleId: 6, parentRoleId: 1),
            $dateTime
        );

        $context = $validator->validate('valid-token');

        $this->assertSame(3, $context->getAdminId());
        $this->assertTrue($context->hasAclResource('Magento_Sales::sales'));
    }

    /**
     * An admin without any authorization_role row resolves to a context with no ACL
     * resources — restricted tools stay Forbidden, public tools keep working.
     */
    public function testResolvesToEmptyResourcesWhenAdminHasNoRole(): void
    {
        $token = $this->mockToken([
            'getRevokedAt' => null,
            'getExpiresAt' => '2030-01-01 00:00:00',
            'getAdminUserId' => 9,
        ]);

        $tokenRepository = $this->createMock(TokenRepository::class);
        $tokenRepository->method('getByAccessToken')->willReturn($token);

        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(strtotime('2026-01-01 00:00:00'));

        $aclRetriever = $this->createMock(AclRetriever::class);
        $aclRetriever->expects($this->never())->method('getAllowedResourcesByRole');

        $validator = new AdminTokenValidator(
            $tokenRepository,
            $aclRetriever,
            $this->mockRoleCollectionFactory(9, userRoleId: null, parentRoleId: null),
            $dateTime
        );

        $context = $validator->validate('valid-token');

        $this->assertSame([], $context->getAllowedAclResources());
        $this->assertFalse($context->hasAclResource('Magento_Sales::sales'));
    }

    /**
     * Builds a RoleCollectionFactory mock whose collection resolves the given admin user
     * to a role item. getParentId() is a magic accessor (AbstractModel::__call()), so the
     * role mock needs addMethods(), same as mockToken() below.
     *
     * @param int|null $userRoleId null simulates "no role row found" (empty first item).
     */
    private function mockRoleCollectionFactory(
        int $adminId,
        ?int $userRoleId,
        ?int $parentRoleId
    ): RoleCollectionFactory&MockObject {
        $role = $this->getMockBuilder(Role::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId'])
            ->addMethods(['getParentId'])
            ->getMock();
        $role->method('getId')->willReturn($userRoleId);
        $role->method('getParentId')->willReturn($parentRoleId);

        $collection = $this->createMock(RoleCollection::class);
        $collection->expects($this->once())
            ->method('setUserFilter')
            ->with($adminId, UserContextInterface::USER_TYPE_ADMIN)
            ->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($role);

        $factory = $this->createMock(RoleCollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        return $factory;
    }

    /**
     * Token's getRevokedAt()/getExpiresAt()/getAdminUserId() are magic accessors added by
     * AbstractModel::__call(), not real declared methods, so createMock()'s reflection-based
     * stubbing can't configure them directly — addMethods() is needed to stub them.
     *
     * @param array<string, mixed> $returns
     */
    private function mockToken(array $returns): Token&MockObject
    {
        $token = $this->getMockBuilder(Token::class)
            ->disableOriginalConstructor()
            ->addMethods(array_keys($returns))
            ->getMock();

        foreach ($returns as $method => $value) {
            $token->method($method)->willReturn($value);
        }

        return $token;
    }
}
