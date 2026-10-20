<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\Config;

class ConfigTest extends TestCase
{
    /**
     * isFullRequestLoggingEnabled() must reflect the "mcp/general/log_full_requests" flag.
     */
    public function testFullRequestLoggingReflectsScopeConfigFlag(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')
            ->with('mcp/general/log_full_requests')
            ->willReturn(true);

        $config = new Config($scopeConfig);

        $this->assertTrue($config->isFullRequestLoggingEnabled());
    }

    /**
     * The flag must be disabled by default, so full payloads are not logged unintentionally.
     */
    public function testFullRequestLoggingDisabledByDefault(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturn(false);

        $config = new Config($scopeConfig);

        $this->assertFalse($config->isFullRequestLoggingEnabled());
    }

    /**
     * isWriteToolsEnabled() must reflect the "mcp/general/enable_write_tools" flag.
     */
    public function testWriteToolsReflectsScopeConfigFlag(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')
            ->with('mcp/general/enable_write_tools')
            ->willReturn(true);

        $config = new Config($scopeConfig);

        $this->assertTrue($config->isWriteToolsEnabled());
    }

    /**
     * Write tools must be disabled by default — writing is opt-in per installation.
     */
    public function testWriteToolsDisabledByDefault(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturn(false);

        $config = new Config($scopeConfig);

        $this->assertFalse($config->isWriteToolsEnabled());
    }

    /**
     * List settings accept commas, spaces and newlines; redirect hosts are lowercased.
     */
    public function testListSettingsAreSplitAndNormalized(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap([
            ['mcp/security/trusted_proxies', 'default', null, "127.0.0.1, ::1\n10.0.0.0/8"],
            ['mcp/security/allowed_redirect_hosts', 'default', null, "Claude.AI\n"],
        ]);

        $config = new Config($scopeConfig);

        $this->assertSame(['127.0.0.1', '::1', '10.0.0.0/8'], $config->getTrustedProxies());
        $this->assertSame(['claude.ai'], $config->getAllowedRedirectHosts());
    }

    /**
     * An empty client IP header setting means "use the connection address only".
     */
    public function testEmptyClientIpHeaderIsNull(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn('  ');

        $this->assertNull((new Config($scopeConfig))->getClientIpHeader());
    }
}
