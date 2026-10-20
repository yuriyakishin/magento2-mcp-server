<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Oauth;

use Magento\Framework\Exception\NoSuchEntityException;
use Yu\McpServer\Model\ResourceModel\Oauth\AuthCode as AuthCodeResource;

/**
 * Persists and loads single-use OAuth authorization codes (mcp_oauth_auth_code). The table
 * holds only SecretHasher digests; lookups take the raw code the client sent.
 */
class AuthCodeRepository
{
    public function __construct(
        private readonly AuthCodeResource $authCodeResource,
        private readonly AuthCodeFactory $authCodeFactory,
        private readonly SecretHasher $secretHasher
    ) {
    }

    /**
     * @param AuthCode $authCode Authorization code entity to persist.
     * @throws \Exception if the authorization code cannot be persisted.
     */
    public function save(AuthCode $authCode): AuthCode
    {
        $this->authCodeResource->save($authCode);

        return $authCode;
    }

    /**
     * @param string $code Raw authorization code value to look up.
     * @throws NoSuchEntityException if no authorization code matches this value.
     */
    public function getByCode(string $code): AuthCode
    {
        $entityId = $this->authCodeResource->getIdByCode($this->secretHasher->hash($code));
        if ($entityId === null) {
            throw new NoSuchEntityException(__('Authorization code was not found.'));
        }

        $authCode = $this->authCodeFactory->create();
        $this->authCodeResource->load($authCode, $entityId);

        return $authCode;
    }
}
