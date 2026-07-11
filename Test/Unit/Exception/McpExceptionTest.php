<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Exception;

use PHPUnit\Framework\TestCase;
use Yu\McpServer\Exception\McpException;

class McpExceptionTest extends TestCase
{
    /**
     * The constructor arguments must be exposed back via getMessage() and getRpcCode().
     */
    public function testExposesMessageAndRpcCode(): void
    {
        $exception = new McpException('Method not found: foo', -32601);

        $this->assertSame('Method not found: foo', $exception->getMessage());
        $this->assertSame(-32601, $exception->getRpcCode());
    }

    /**
     * McpException must extend RuntimeException so untyped `catch` blocks still see it.
     */
    public function testIsARuntimeException(): void
    {
        $exception = new McpException('boom', -32603);

        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }
}
