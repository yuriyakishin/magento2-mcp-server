<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Exception;

use PHPUnit\Framework\TestCase;
use Yu\McpServer\Exception\McpException;
use Yu\McpServer\Exception\UnauthorizedException;

class UnauthorizedExceptionTest extends TestCase
{
    /**
     * UnauthorizedException must carry a fixed, distinct JSON-RPC error code.
     */
    public function testHasFixedRpcCode(): void
    {
        $exception = new UnauthorizedException('no valid identity');

        $this->assertSame(-32001, $exception->getRpcCode());
        $this->assertSame('no valid identity', $exception->getMessage());
    }

    /**
     * It must extend McpException so JsonRpcHandler's generic catch block handles it.
     */
    public function testIsAnMcpException(): void
    {
        $exception = new UnauthorizedException('no valid identity');

        $this->assertInstanceOf(McpException::class, $exception);
    }
}
