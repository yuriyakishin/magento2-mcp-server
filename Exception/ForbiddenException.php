<?php

declare(strict_types=1);

namespace Yu\McpServer\Exception;

/**
 * A valid identity was resolved, but it lacks the ACL resource the requested tool needs.
 */
class ForbiddenException extends McpException
{
    public function __construct(string $message)
    {
        parent::__construct($message, -32002);
    }
}
