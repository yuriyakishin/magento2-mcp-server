<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Auth;

use Magento\User\Model\ResourceModel\User as UserResource;
use Magento\User\Model\UserFactory;

/**
 * Whether an admin account may still use its MCP tokens: it must exist and be active.
 * Checked on every authenticated request and refresh, so deactivating or deleting an admin
 * in Magento cuts off MCP access immediately rather than when the tokens run out.
 */
class AdminUserStatus
{
    public function __construct(
        private readonly UserFactory $userFactory,
        private readonly UserResource $userResource
    ) {
    }

    /**
     * @param int $adminUserId Admin user id the token is bound to.
     */
    public function isActive(int $adminUserId): bool
    {
        $user = $this->userFactory->create();
        $this->userResource->load($user, $adminUserId);

        return $user->getId() && (int)$user->getIsActive() === 1;
    }
}
