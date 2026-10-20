<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Oauth;

use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\Config;
use Yu\McpServer\Model\Oauth\RedirectUriPolicy;

class RedirectUriPolicyTest extends TestCase
{
    /**
     * Ordinary https and custom-scheme (desktop/IDE client) URIs are accepted.
     */
    public function testAcceptsHttpsAndCustomSchemes(): void
    {
        $policy = $this->policy([]);

        $this->assertNull($policy->getRegistrationError('https://claude.ai/api/mcp/auth_callback'));
        $this->assertNull($policy->getRegistrationError('cursor://anysphere.cursor-retrieval/oauth/callback'));
        $this->assertNull($policy->getRegistrationError('http://127.0.0.1:33418/callback'));
    }

    /**
     * Script-capable schemes, fragments and non-strings are rejected.
     */
    public function testRejectsDangerousUris(): void
    {
        $policy = $this->policy([]);

        $this->assertNotNull($policy->getRegistrationError('javascript://x/%0aalert(1)'));
        $this->assertNotNull($policy->getRegistrationError('https://claude.ai/cb#frag'));
        $this->assertNotNull($policy->getRegistrationError('not a uri'));
        $this->assertNotNull($policy->getRegistrationError(['https://claude.ai']));
    }

    /**
     * With an allowlist configured only listed hosts pass, case-insensitively.
     */
    public function testAllowlistRestrictsHosts(): void
    {
        $policy = $this->policy(['claude.ai']);

        $this->assertTrue($policy->isHostAllowed('https://Claude.AI/api/mcp/auth_callback'));
        $this->assertFalse($policy->isHostAllowed('https://evil.example/callback'));
        $this->assertNotNull($policy->getRegistrationError('https://evil.example/callback'));
    }

    /**
     * An empty allowlist allows any host.
     */
    public function testEmptyAllowlistAllowsAnyHost(): void
    {
        $this->assertTrue($this->policy([])->isHostAllowed('https://anything.example/cb'));
    }

    /**
     * The sign-in form shows scheme, host and port — not the full path.
     */
    public function testDescribeShowsSchemeHostAndPort(): void
    {
        $policy = $this->policy([]);

        $this->assertSame('https://claude.ai', $policy->describe('https://claude.ai/api/mcp/auth_callback'));
        $this->assertSame('http://127.0.0.1:33418', $policy->describe('http://127.0.0.1:33418/callback'));
    }

    /**
     * @param string[] $allowedHosts Configured redirect host allowlist.
     */
    private function policy(array $allowedHosts): RedirectUriPolicy
    {
        $config = $this->createMock(Config::class);
        $config->method('getAllowedRedirectHosts')->willReturn($allowedHosts);

        return new RedirectUriPolicy($config);
    }
}
