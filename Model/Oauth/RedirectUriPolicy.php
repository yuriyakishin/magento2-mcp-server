<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Oauth;

use Yu\McpServer\Model\Config;

/**
 * Decides which OAuth redirect URIs are acceptable and how they are shown to the admin on
 * the sign-in form. Client registration is open to anyone, so the redirect URI is the only
 * thing standing between a phishing link and an authorization code sent to a stranger.
 */
class RedirectUriPolicy
{
    private const FORBIDDEN_SCHEMES = ['javascript', 'data', 'vbscript', 'file', 'blob'];

    public function __construct(
        private readonly Config $config
    ) {
    }

    /**
     * Returns why a URI can't be registered as a redirect URI, or null when it can.
     *
     * Custom schemes stay allowed (desktop/IDE clients use them, e.g. "cursor://...").
     *
     * @param mixed $uri Value from the registration request.
     */
    public function getRegistrationError(mixed $uri): ?string
    {
        if (!is_string($uri) || filter_var($uri, FILTER_VALIDATE_URL) === false) {
            return sprintf('"%s" is not a valid URI.', is_string($uri) ? $uri : gettype($uri));
        }

        $parts = parse_url($uri);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if (in_array($scheme, self::FORBIDDEN_SCHEMES, true)) {
            return sprintf('The "%s" scheme is not allowed in redirect URIs.', $scheme);
        }
        if (isset($parts['fragment'])) {
            return sprintf('"%s" must not contain a fragment.', $uri);
        }
        if (!$this->isHostAllowed($uri)) {
            return sprintf('"%s" points to a host this store does not allow.', $uri);
        }

        return null;
    }

    /**
     * Whether the URI's host is on the configured allowlist (always true when it's empty).
     *
     * @param string $uri Redirect URI to check.
     */
    public function isHostAllowed(string $uri): bool
    {
        $allowedHosts = $this->config->getAllowedRedirectHosts();
        if ($allowedHosts === []) {
            return true;
        }

        $host = strtolower((string)parse_url($uri, PHP_URL_HOST));

        return $host !== '' && in_array($host, $allowedHosts, true);
    }

    /**
     * Short "where does the code go" label for the sign-in form: scheme, host and port.
     *
     * @param string $uri Redirect URI to describe.
     */
    public function describe(string $uri): string
    {
        $parts = parse_url($uri);
        $label = ($parts['scheme'] ?? '') . '://' . ($parts['host'] ?? '');
        if (isset($parts['port'])) {
            $label .= ':' . $parts['port'];
        }

        return $label;
    }
}
