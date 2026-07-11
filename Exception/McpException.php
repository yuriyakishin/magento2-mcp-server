<?php

declare(strict_types=1);

namespace Yu\McpServer\Exception;

/**
 * Protocol-level JSON-RPC error (not a tool execution error).
 */
class McpException extends \RuntimeException
{
    public function __construct(string $message, private readonly int $rpcCode)
    {
        parent::__construct($message);
    }

    /**
     * JSON-RPC error code to report to the client.
     */
    public function getRpcCode(): int
    {
        return $this->rpcCode;
    }
}
