<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Auth;

use Magento\Framework\App\Request\Http;
use Yu\McpServer\Model\Config;

/**
 * Resolves the caller's IP address for rate limiting.
 *
 * The connection address (REMOTE_ADDR) is the default. A forwarding header is honored only
 * when the connection comes from a configured trusted proxy — otherwise any client could
 * pick its own rate-limit bucket by sending the header itself. Request::getClientIp() must
 * not be used for this: it trusts Client-IP / X-Forwarded-For from anyone.
 */
class ClientIpResolver
{
    public function __construct(
        private readonly Http $request,
        private readonly Config $config
    ) {
    }

    /**
     * Returns the client IP, or an empty string when the server provides none.
     */
    public function resolve(): string
    {
        $remoteAddress = (string)$this->request->getServer('REMOTE_ADDR');
        $header = $this->config->getClientIpHeader();
        $trustedProxies = $this->config->getTrustedProxies();

        if ($header === null || !$this->isTrusted($remoteAddress, $trustedProxies)) {
            return $remoteAddress;
        }

        $headerValue = $this->request->getHeader($header);
        if (!is_string($headerValue) || $headerValue === '') {
            return $remoteAddress;
        }

        // Proxies append to the right, so the right-most address that isn't one of our own
        // proxies is the one the outermost trusted proxy actually saw.
        $candidates = array_reverse(array_map('trim', explode(',', $headerValue)));
        foreach ($candidates as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
                return $remoteAddress;
            }
            if (!$this->isTrusted($candidate, $trustedProxies)) {
                return $candidate;
            }
        }

        return $remoteAddress;
    }

    /**
     * Whether the address matches one of the trusted proxy entries (exact IP or CIDR).
     *
     * @param string $ipAddress Address to check.
     * @param string[] $trustedProxies Configured IPs and CIDR ranges.
     */
    private function isTrusted(string $ipAddress, array $trustedProxies): bool
    {
        if (filter_var($ipAddress, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        $address = inet_pton($ipAddress);

        foreach ($trustedProxies as $entry) {
            if ($this->matches($address, $entry)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $address Binary address from inet_pton().
     * @param string $entry Trusted proxy entry: "203.0.113.5" or "203.0.113.0/24".
     */
    private function matches(string $address, string $entry): bool
    {
        [$subnet, $bits] = array_pad(explode('/', $entry, 2), 2, null);
        if (filter_var($subnet, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        $network = inet_pton($subnet);
        if (strlen($network) !== strlen($address)) {
            return false;
        }

        $maxBits = strlen($address) * 8;
        $bits = $bits === null ? $maxBits : (int)$bits;
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }

        $fullBytes = intdiv($bits, 8);
        if (substr($address, 0, $fullBytes) !== substr($network, 0, $fullBytes)) {
            return false;
        }

        $remainingBits = $bits % 8;
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($address[$fullBytes]) & $mask) === (ord($network[$fullBytes]) & $mask);
    }
}
