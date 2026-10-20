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
    private const XML_PATH_CLIENT_IP_HEADER = 'mcp/security/client_ip_header';
    private const XML_PATH_TRUSTED_PROXIES = 'mcp/security/trusted_proxies';
    private const XML_PATH_ALLOWED_REDIRECT_HOSTS = 'mcp/security/allowed_redirect_hosts';

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

    /**
     * Request header carrying the real client IP when the store sits behind a proxy
     * (e.g. "CF-Connecting-IP", "X-Forwarded-For"), or null to use REMOTE_ADDR only.
     */
    public function getClientIpHeader(): ?string
    {
        $header = trim((string)$this->scopeConfig->getValue(self::XML_PATH_CLIENT_IP_HEADER));

        return $header !== '' ? $header : null;
    }

    /**
     * Proxy addresses/CIDR ranges allowed to supply the client IP header.
     *
     * @return string[]
     */
    public function getTrustedProxies(): array
    {
        return $this->readList(self::XML_PATH_TRUSTED_PROXIES);
    }

    /**
     * Hosts OAuth redirect URIs may point to (lowercase); empty means any host.
     *
     * @return string[]
     */
    public function getAllowedRedirectHosts(): array
    {
        return array_map('strtolower', $this->readList(self::XML_PATH_ALLOWED_REDIRECT_HOSTS));
    }

    /**
     * Splits a comma/whitespace-separated config value into a list of non-empty entries.
     *
     * @param string $path Config path to read.
     * @return string[]
     */
    private function readList(string $path): array
    {
        $value = (string)$this->scopeConfig->getValue($path);

        return array_values(array_filter(preg_split('/[\s,]+/', $value) ?: [], 'strlen'));
    }
}
