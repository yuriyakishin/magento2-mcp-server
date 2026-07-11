<?php

declare(strict_types=1);

namespace Yu\McpServer\Exception;

/**
 * No valid identity at all: missing context where one is required, or an invalid,
 * revoked, or expired bearer token.
 */
class UnauthorizedException extends McpException
{
    public function __construct(string $message)
    {
        parent::__construct($message, -32001);
    }
}
