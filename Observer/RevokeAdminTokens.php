<?php

declare(strict_types=1);

namespace Yu\McpServer\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\User\Model\User;
use Yu\McpServer\Model\ResourceModel\Oauth\Token as TokenResource;

/**
 * Revokes every MCP token of an admin whose password changed or who was deactivated
 * (admin_user_save_after), so a password reset ends sessions on other devices the same way
 * it would for a stolen admin-panel session.
 */
class RevokeAdminTokens implements ObserverInterface
{
    public function __construct(
        private readonly TokenResource $tokenResource,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * @param Observer $observer Event carrying the saved admin user as "object".
     */
    public function execute(Observer $observer): void
    {
        $user = $observer->getEvent()->getObject();
        if (!$user instanceof User || !$user->getId()) {
            return;
        }

        $passwordChanged = $user->getPassword() && $user->dataHasChangedFor('password');
        $deactivated = (int)$user->getIsActive() !== 1;
        if (!$passwordChanged && !$deactivated) {
            return;
        }

        $this->tokenResource->revokeAllForAdmin(
            (int)$user->getId(),
            gmdate('Y-m-d H:i:s', $this->dateTime->gmtTimestamp())
        );
    }
}
