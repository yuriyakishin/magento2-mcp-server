<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Oauth;

use Magento\Framework\Exception\NoSuchEntityException;
use Yu\McpServer\Model\ResourceModel\Oauth\Client as ClientResource;

/**
 * Persists and loads registered OAuth clients (mcp_oauth_client).
 */
class ClientRepository
{
    public function __construct(
        private readonly ClientResource $clientResource,
        private readonly ClientFactory $clientFactory
    ) {
    }

    /**
     * @throws \Exception if the client cannot be persisted.
     */
    public function save(Client $client): Client
    {
        $this->clientResource->save($client);

        return $client;
    }

    /**
     * @throws NoSuchEntityException if no client is registered under this id.
     */
    public function getById(string $clientId): Client
    {
        $entityId = $this->clientResource->getIdByClientId($clientId);
        if ($entityId === null) {
            throw new NoSuchEntityException(__('OAuth client "%1" was not found.', $clientId));
        }

        $client = $this->clientFactory->create();
        $this->clientResource->load($client, $entityId);

        return $client;
    }
}
