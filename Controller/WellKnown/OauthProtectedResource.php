<?php

declare(strict_types=1);

namespace Yu\McpServer\Controller\WellKnown;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;

/**
 * GET /.well-known/oauth-protected-resource — RFC 9728 Protected Resource Metadata.
 *
 * Tells an MCP client which authorization server issues tokens accepted by POST /mcp,
 * so the client doesn't need /mcp/oauth/* paths hardcoded.
 */
class OauthProtectedResource implements HttpGetActionInterface
{
    public function __construct(
        private readonly Http $request,
        private readonly ResultFactory $resultFactory
    ) {
    }

    /**
     * @return ResultInterface JSON metadata document per RFC 9728.
     */
    public function execute(): ResultInterface
    {
        $baseUrl = $this->request->getScheme() . '://' . $this->request->getHttpHost();

        /** @var Json $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        $result->setData([
            'resource' => $baseUrl . '/mcp',
            'authorization_servers' => [$baseUrl],
        ]);

        return $result;
    }
}
