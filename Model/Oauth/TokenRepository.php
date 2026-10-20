<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Oauth;

use Magento\Framework\Exception\NoSuchEntityException;
use Yu\McpServer\Model\ResourceModel\Oauth\Token as TokenResource;

/**
 * Persists and loads OAuth access/refresh tokens (mcp_oauth_token). The table holds only
 * SecretHasher digests; lookups take the raw value the client sent.
 */
class TokenRepository
{
    public function __construct(
        private readonly TokenResource $tokenResource,
        private readonly TokenFactory $tokenFactory,
        private readonly SecretHasher $secretHasher
    ) {
    }

    /**
     * @param Token $token OAuth token entity to persist.
     * @throws \Exception if the token cannot be persisted.
     */
    public function save(Token $token): Token
    {
        $this->tokenResource->save($token);

        return $token;
    }

    /**
     * @param string $accessToken Raw access token value to look up.
     * @throws NoSuchEntityException if no token matches this access token value.
     */
    public function getByAccessToken(string $accessToken): Token
    {
        $entityId = $this->tokenResource->getIdByAccessToken($this->secretHasher->hash($accessToken));
        if ($entityId === null) {
            throw new NoSuchEntityException(__('Access token was not found.'));
        }

        $token = $this->tokenFactory->create();
        $this->tokenResource->load($token, $entityId);

        return $token;
    }

    /**
     * @param string $refreshToken Raw refresh token value to look up.
     * @throws NoSuchEntityException if no token matches this refresh token value.
     */
    public function getByRefreshToken(string $refreshToken): Token
    {
        $entityId = $this->tokenResource->getIdByRefreshToken($this->secretHasher->hash($refreshToken));
        if ($entityId === null) {
            throw new NoSuchEntityException(__('Refresh token was not found.'));
        }

        $token = $this->tokenFactory->create();
        $this->tokenResource->load($token, $entityId);

        return $token;
    }
}
