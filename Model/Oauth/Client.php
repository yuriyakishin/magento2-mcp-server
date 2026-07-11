<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Oauth;

use Magento\Framework\Model\AbstractModel;
use Yu\McpServer\Model\ResourceModel\Oauth\Client as ClientResource;

/**
 * A registered OAuth client (see Controller/Oauth/Register.php).
 *
 * @method string getClientId()
 * @method $this setClientId(string $clientId)
 * @method string|null getClientName()
 * @method $this setClientName(?string $clientName)
 * @method string getRedirectUris()
 * @method $this setRedirectUris(string $redirectUris)
 */
class Client extends AbstractModel
{
    /**
     * Binds this model to its resource model.
     */
    protected function _construct()
    {
        $this->_init(ClientResource::class);
    }
}
