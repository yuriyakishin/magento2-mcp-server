<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Auth;

use Magento\Authorization\Model\Acl\AclRetriever;
use Magento\Authorization\Model\ResourceModel\Role\CollectionFactory as RoleCollectionFactory;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Yu\McpServer\Exception\UnauthorizedException;
use Yu\McpServer\Model\Oauth\TokenRepository;

/**
 * Resolves an `Authorization: Bearer <token>` header value into an AdminContext.
 */
class AdminTokenValidator
{
    public function __construct(
        private readonly TokenRepository $tokenRepository,
        private readonly AclRetriever $aclRetriever,
        private readonly RoleCollectionFactory $roleCollectionFactory,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * @throws UnauthorizedException if the token is unknown, revoked, or expired.
     */
    public function validate(string $bearerToken): AdminContext
    {
        try {
            $token = $this->tokenRepository->getByAccessToken($bearerToken);
        } catch (NoSuchEntityException) {
            throw new UnauthorizedException('The access token is invalid.');
        }

        if ($token->getRevokedAt() !== null) {
            throw new UnauthorizedException('The access token has been revoked.');
        }

        if (strtotime((string) $token->getExpiresAt()) <= $this->dateTime->gmtTimestamp()) {
            throw new UnauthorizedException('The access token has expired.');
        }

        $adminId = (int) $token->getAdminUserId();

        return new AdminContext($adminId, $this->resolveAllowedResources($adminId));
    }

    /**
     * Loads the admin's allowed ACL resources.
     *
     * AclRetriever::getAllowedResourcesByUser() can't be used here: it reads
     * authorization_rule by the admin's personal U-type role id, but admin permissions
     * are stored on the parent G-type group role (e.g. "Administrators"), so for any
     * admin user it returns an empty list. Resolve the user's role, then read the rules
     * from its parent group role instead.
     *
     * @return string[]
     */
    private function resolveAllowedResources(int $adminId): array
    {
        $userRole = $this->roleCollectionFactory->create()
            ->setUserFilter($adminId, UserContextInterface::USER_TYPE_ADMIN)
            ->getFirstItem();

        if (!$userRole->getId()) {
            return [];
        }

        $groupRoleId = (int) $userRole->getParentId() ?: (int) $userRole->getId();

        return $this->aclRetriever->getAllowedResourcesByRole($groupRoleId);
    }
}
