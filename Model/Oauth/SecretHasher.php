<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Oauth;

/**
 * One-way hash under which OAuth secrets (authorization codes, access and refresh tokens)
 * are stored, so a leaked database or backup can't be replayed against the endpoint. The
 * secrets are 256-bit random values, so a plain unsalted SHA-256 is enough and stays
 * lookup-friendly; Setup/Patch/Data/HashStoredOauthSecrets applies the same function in SQL.
 */
class SecretHasher
{
    /**
     * @param string $secret Raw secret as sent to or received from the client.
     * @return string Lowercase hex SHA-256 digest (64 characters).
     */
    public function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }
}
