<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Auth;

use Magento\User\Model\Backend\Config\ObserverConfig;
use Magento\User\Model\ResourceModel\User as UserResource;

/**
 * Mirrors Magento's forced admin password change for the MCP sign-in form. The vendor
 * enforcement (ForceAdminPasswordChangeObserver) is an adminhtml predispatch observer, so
 * the frontend-area MCP flow never reaches it.
 */
class PasswordExpiryCheck
{
    public function __construct(
        private readonly ObserverConfig $observerConfig,
        private readonly UserResource $userResource
    ) {
    }

    /**
     * Whether the admin must change an expired password before signing in.
     *
     * @param int $adminUserId Admin user who just passed the password check.
     */
    public function isChangeRequired(int $adminUserId): bool
    {
        if (!$this->observerConfig->isPasswordChangeForced()) {
            return false;
        }

        $latestPassword = $this->userResource->getLatestPassword($adminUserId);

        return is_array($latestPassword) && $this->observerConfig->_isLatestPasswordExpired($latestPassword);
    }
}
