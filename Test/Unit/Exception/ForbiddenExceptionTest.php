<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Exception;

use PHPUnit\Framework\TestCase;
use Yu\McpServer\Exception\ForbiddenException;
use Yu\McpServer\Exception\McpException;

class ForbiddenExceptionTest extends TestCase
{
    /**
     * ForbiddenException must carry a fixed, distinct JSON-RPC error code.
     */
    public function testHasFixedRpcCode(): void
    {
        $exception = new ForbiddenException('missing ACL resource');

        $this->assertSame(-32002, $exception->getRpcCode());
        $this->assertSame('missing ACL resource', $exception->getMessage());
    }

    /**
     * It must extend McpException so JsonRpcHandler's generic catch block handles it.
     */
    public function testIsAnMcpException(): void
    {
        $exception = new ForbiddenException('missing ACL resource');

        $this->assertInstanceOf(McpException::class, $exception);
    }
}
