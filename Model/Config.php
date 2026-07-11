<?php

declare(strict_types=1);

namespace Yu\McpServer\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Reads Yu_McpServer system configuration (Stores > Configuration > Advanced > MCP Server).
 */
class Config
{
    private const XML_PATH_LOG_FULL_REQUESTS = 'mcp/general/log_full_requests';
    private const XML_PATH_ENABLE_WRITE_TOOLS = 'mcp/general/enable_write_tools';
    private const XML_PATH_ENABLE_PUBLIC_TOOLS = 'mcp/general/enable_public_tools';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Whether full JSON-RPC request/response bodies should be logged at debug level.
     * Disabled by default — payloads can contain customer emails, order numbers, and other
     * personal data, so this must be turned on deliberately and only while debugging.
     */
    public function isFullRequestLoggingEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_LOG_FULL_REQUESTS);
    }

    /**
     * Whether write tools (WriteToolInterface implementations) are enabled at all.
     * Disabled by default: with the flag off they are hidden from tools/list and rejected
     * at tools/call regardless of the caller's ACL rights.
     */
    public function isWriteToolsEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLE_WRITE_TOOLS);
    }

    /**
     * Whether tools without an ACL resource may be called anonymously. Enabled by default
     * (the public tools expose storefront-visible data only). When disabled, every
     * tools/call requires an authenticated admin; tools/list stays open so OAuth-aware
     * clients can still discover the tools and trigger their login flow.
     */
    public function isPublicToolsEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLE_PUBLIC_TOOLS);
    }
}
