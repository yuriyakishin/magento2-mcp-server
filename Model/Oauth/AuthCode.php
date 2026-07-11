<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Oauth;

use Magento\Framework\Model\AbstractModel;
use Yu\McpServer\Model\ResourceModel\Oauth\AuthCode as AuthCodeResource;

/**
 * A short-lived, single-use OAuth authorization code bound to an admin user and a PKCE challenge.
 *
 * @method string getCode()
 * @method $this setCode(string $code)
 * @method string getClientId()
 * @method $this setClientId(string $clientId)
 * @method int getAdminUserId()
 * @method $this setAdminUserId(int $adminUserId)
 * @method string getCodeChallenge()
 * @method $this setCodeChallenge(string $codeChallenge)
 * @method string getCodeChallengeMethod()
 * @method $this setCodeChallengeMethod(string $codeChallengeMethod)
 * @method string getRedirectUri()
 * @method $this setRedirectUri(string $redirectUri)
 * @method string getExpiresAt()
 * @method $this setExpiresAt(string $expiresAt)
 * @method string|null getUsedAt()
 * @method $this setUsedAt(?string $usedAt)
 */
class AuthCode extends AbstractModel
{
    /**
     * Binds this model to its resource model.
     */
    protected function _construct()
    {
        $this->_init(AuthCodeResource::class);
    }
}
