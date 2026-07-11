<?php

declare(strict_types=1);

namespace Yu\McpServer\Controller\WellKnown;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;

/**
 * GET /.well-known/oauth-authorization-server — RFC 8414 Authorization Server Metadata.
 *
 * Lets an MCP client discover the OAuth 2.1 + PKCE endpoints implemented in
 * Controller/Oauth/* without hardcoding their paths.
 */
class OauthAuthorizationServer implements HttpGetActionInterface
{
    public function __construct(
        private readonly Http $request,
        private readonly ResultFactory $resultFactory
    ) {
    }

    /**
     * @return ResultInterface JSON metadata document per RFC 8414.
     */
    public function execute(): ResultInterface
    {
        $baseUrl = $this->request->getScheme() . '://' . $this->request->getHttpHost();

        /** @var Json $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        $result->setData([
            'issuer' => $baseUrl,
            'authorization_endpoint' => $baseUrl . '/mcp/oauth/authorize',
            'token_endpoint' => $baseUrl . '/mcp/oauth/token',
            'registration_endpoint' => $baseUrl . '/mcp/oauth/register',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
        ]);

        return $result;
    }
}
