<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Oauth;

use Magento\Framework\Exception\NoSuchEntityException;
use Yu\McpServer\Model\ResourceModel\Oauth\Token as TokenResource;

/**
 * Persists and loads OAuth access/refresh tokens (mcp_oauth_token).
 */
class TokenRepository
{
    public function __construct(
        private readonly TokenResource $tokenResource,
        private readonly TokenFactory $tokenFactory
    ) {
    }

    /**
     * @throws \Exception if the token cannot be persisted.
     */
    public function save(Token $token): Token
    {
        $this->tokenResource->save($token);

        return $token;
    }

    /**
     * @throws NoSuchEntityException if no token matches this access token value.
     */
    public function getByAccessToken(string $accessToken): Token
    {
        $entityId = $this->tokenResource->getIdByAccessToken($accessToken);
        if ($entityId === null) {
            throw new NoSuchEntityException(__('Access token was not found.'));
        }

        $token = $this->tokenFactory->create();
        $this->tokenResource->load($token, $entityId);

        return $token;
    }

    /**
     * @throws NoSuchEntityException if no token matches this refresh token value.
     */
    public function getByRefreshToken(string $refreshToken): Token
    {
        $entityId = $this->tokenResource->getIdByRefreshToken($refreshToken);
        if ($entityId === null) {
            throw new NoSuchEntityException(__('Refresh token was not found.'));
        }

        $token = $this->tokenFactory->create();
        $this->tokenResource->load($token, $entityId);

        return $token;
    }
}
