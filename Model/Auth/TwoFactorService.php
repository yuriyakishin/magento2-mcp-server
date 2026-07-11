<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Auth;

use Magento\Framework\DataObjectFactory;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\TwoFactorAuth\Api\TfaInterface;
use Magento\TwoFactorAuth\Model\Provider\Engine\Google;
use Magento\User\Model\ResourceModel\User as UserResource;
use Magento\User\Model\UserFactory;

/**
 * Bridges the OAuth login flow to Magento_TwoFactorAuth.
 *
 * The vendor module enforces 2FA via a controller_action_predispatch observer registered
 * only in the adminhtml area — Magento\Backend\Model\Auth::login() itself checks nothing
 * beyond the password. Since Controller/Oauth/Authorize.php runs in the frontend area and
 * never dispatches an adminhtml action, that observer can't protect this flow; this service
 * re-creates the check explicitly: it decides whether an admin must present a TOTP code and
 * verifies the code through the vendor Google (authenticator app) engine.
 *
 * The dependency on Magento_TwoFactorAuth is soft: both vendor services are injected as
 * proxies (see etc/di.xml) and only ever touched after ModuleManager confirms the module is
 * enabled, so the MCP module still installs and runs on stores that disabled 2FA.
 */
class TwoFactorService
{
    public const RESULT_NOT_REQUIRED = 'not_required';
    public const RESULT_REQUIRED = 'required';
    public const RESULT_NOT_CONFIGURED = 'not_configured';
    public const RESULT_UNSUPPORTED = 'unsupported';

    private const TFA_MODULE = 'Magento_TwoFactorAuth';

    public function __construct(
        private readonly ModuleManager $moduleManager,
        private readonly TfaInterface $tfa,
        private readonly Google $googleEngine,
        private readonly UserFactory $userFactory,
        private readonly UserResource $userResource,
        private readonly DataObjectFactory $dataObjectFactory
    ) {
    }

    /**
     * Decides what the login flow must do about 2FA for this admin after the password step.
     *
     * @return string one of the RESULT_* constants:
     *   - RESULT_NOT_REQUIRED: proceed without a code (module disabled or no provider forced)
     *   - RESULT_REQUIRED: the user has an activated authenticator app — ask for a code
     *   - RESULT_NOT_CONFIGURED: 2FA is enforced but the user hasn't finished setting it up
     *   - RESULT_UNSUPPORTED: 2FA is enforced through a provider this form can't drive
     */
    public function check(int $adminUserId): string
    {
        if (!$this->moduleManager->isEnabled(self::TFA_MODULE) || !$this->tfa->isEnabled()) {
            return self::RESULT_NOT_REQUIRED;
        }

        $providers = $this->tfa->getUserProviders($adminUserId);
        if ($providers === []) {
            return self::RESULT_NOT_REQUIRED;
        }

        foreach ($providers as $provider) {
            if ($provider->getCode() === Google::CODE) {
                return $provider->isActive($adminUserId)
                    ? self::RESULT_REQUIRED
                    : self::RESULT_NOT_CONFIGURED;
            }
        }

        return self::RESULT_UNSUPPORTED;
    }

    /**
     * Verifies a TOTP code against the admin's configured authenticator-app secret.
     */
    public function verifyCode(int $adminUserId, string $code): bool
    {
        if ($code === '') {
            return false;
        }

        $user = $this->userFactory->create();
        $this->userResource->load($user, $adminUserId);
        if (!$user->getId()) {
            return false;
        }

        try {
            return $this->googleEngine->verify(
                $user,
                $this->dataObjectFactory->create(['data' => ['tfa_code' => $code]])
            );
        } catch (\Throwable) {
            // A missing/undecryptable secret (NoSuchEntityException) or a malformed code
            // must read as "wrong code", never as a skipped check.
            return false;
        }
    }
}
