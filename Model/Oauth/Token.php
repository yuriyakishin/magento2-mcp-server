<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Oauth;

use Magento\Framework\Model\AbstractModel;
use Yu\McpServer\Model\ResourceModel\Oauth\Token as TokenResource;

/**
 * An OAuth access/refresh token pair issued to an admin user for a given client.
 *
 * @method string getAccessToken()
 * @method $this setAccessToken(string $accessToken)
 * @method string|null getRefreshToken()
 * @method $this setRefreshToken(?string $refreshToken)
 * @method string getClientId()
 * @method $this setClientId(string $clientId)
 * @method int getAdminUserId()
 * @method $this setAdminUserId(int $adminUserId)
 * @method string getExpiresAt()
 * @method $this setExpiresAt(string $expiresAt)
 * @method string|null getRevokedAt()
 * @method $this setRevokedAt(?string $revokedAt)
 */
class Token extends AbstractModel
{
    /**
     * Binds this model to its resource model.
     */
    protected function _construct()
    {
        $this->_init(TokenResource::class);
    }
}
