<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Auth;

use Magento\Framework\App\Request\Http;
use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\Auth\ClientIpResolver;
use Yu\McpServer\Model\Config;

class ClientIpResolverTest extends TestCase
{
    /**
     * Without a configured header the connection address is used, whatever headers say.
     */
    public function testUsesRemoteAddressWhenNoHeaderConfigured(): void
    {
        $resolver = $this->resolver('203.0.113.7', ['X-Forwarded-For' => '198.51.100.1'], null, []);

        $this->assertSame('203.0.113.7', $resolver->resolve());
    }

    /**
     * A header sent by a client that isn't a trusted proxy must be ignored — otherwise
     * any client could choose its own rate-limit bucket.
     */
    public function testIgnoresHeaderFromUntrustedConnection(): void
    {
        $resolver = $this->resolver(
            '203.0.113.7',
            ['CF-Connecting-IP' => '198.51.100.1'],
            'CF-Connecting-IP',
            ['127.0.0.1']
        );

        $this->assertSame('203.0.113.7', $resolver->resolve());
    }

    /**
     * Behind a trusted proxy the configured header supplies the client address.
     */
    public function testUsesHeaderFromTrustedProxy(): void
    {
        $resolver = $this->resolver(
            '127.0.0.1',
            ['CF-Connecting-IP' => '198.51.100.1'],
            'CF-Connecting-IP',
            ['127.0.0.1']
        );

        $this->assertSame('198.51.100.1', $resolver->resolve());
    }

    /**
     * In an X-Forwarded-For chain the right-most non-proxy address wins; spoofed entries
     * the client prepended on the left are never reached.
     */
    public function testPicksRightMostUntrustedAddressFromChain(): void
    {
        $resolver = $this->resolver(
            '10.0.0.5',
            ['X-Forwarded-For' => '1.1.1.1, 198.51.100.1, 10.0.0.9'],
            'X-Forwarded-For',
            ['10.0.0.0/8']
        );

        $this->assertSame('198.51.100.1', $resolver->resolve());
    }

    /**
     * IPv6 CIDR entries must match like IPv4 ones.
     */
    public function testMatchesIpv6Cidr(): void
    {
        $resolver = $this->resolver(
            '2001:db8::17',
            ['CF-Connecting-IP' => '198.51.100.1'],
            'CF-Connecting-IP',
            ['2001:db8::/32']
        );

        $this->assertSame('198.51.100.1', $resolver->resolve());
    }

    /**
     * A malformed header value falls back to the connection address.
     */
    public function testFallsBackOnInvalidHeaderValue(): void
    {
        $resolver = $this->resolver(
            '127.0.0.1',
            ['CF-Connecting-IP' => 'not-an-ip'],
            'CF-Connecting-IP',
            ['127.0.0.1']
        );

        $this->assertSame('127.0.0.1', $resolver->resolve());
    }

    /**
     * @param string $remoteAddress REMOTE_ADDR of the connection.
     * @param array<string, string> $headers Request headers by name.
     * @param string|null $header Configured client IP header.
     * @param string[] $trustedProxies Configured trusted proxies.
     */
    private function resolver(
        string $remoteAddress,
        array $headers,
        ?string $header,
        array $trustedProxies
    ): ClientIpResolver {
        $request = $this->createMock(Http::class);
        $request->method('getServer')->with('REMOTE_ADDR')->willReturn($remoteAddress);
        $request->method('getHeader')->willReturnCallback(
            static fn (string $name) => $headers[$name] ?? false
        );

        $config = $this->createMock(Config::class);
        $config->method('getClientIpHeader')->willReturn($header);
        $config->method('getTrustedProxies')->willReturn($trustedProxies);

        return new ClientIpResolver($request, $config);
    }
}
