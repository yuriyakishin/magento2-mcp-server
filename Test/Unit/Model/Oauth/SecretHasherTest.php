<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Oauth;

use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\Oauth\SecretHasher;

class SecretHasherTest extends TestCase
{
    /**
     * The digest must equal MySQL's SHA2(value, 256) — the data patch that migrates old
     * plain-text rows relies on both producing the same lowercase hex string.
     */
    public function testMatchesMysqlSha2Format(): void
    {
        $this->assertSame(
            'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad',
            (new SecretHasher())->hash('abc')
        );
    }
}
