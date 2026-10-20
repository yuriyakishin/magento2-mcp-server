<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Oauth;

use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\Oauth\AuthCode;
use Yu\McpServer\Model\Oauth\AuthCodeFactory;
use Yu\McpServer\Model\Oauth\AuthCodeRepository;
use Yu\McpServer\Model\Oauth\SecretHasher;
use Yu\McpServer\Model\Oauth\Token;
use Yu\McpServer\Model\Oauth\TokenFactory;
use Yu\McpServer\Model\Oauth\TokenRepository;
use Yu\McpServer\Model\ResourceModel\Oauth\AuthCode as AuthCodeResource;
use Yu\McpServer\Model\ResourceModel\Oauth\Token as TokenResource;

class TokenRepositoryTest extends TestCase
{
    /**
     * Lookups must search by the digest of the raw value, never by the raw value itself.
     */
    public function testTokenLookupsUseTheDigest(): void
    {
        $resource = $this->createMock(TokenResource::class);
        $resource->expects($this->once())->method('getIdByAccessToken')
            ->with(hash('sha256', 'raw-access'))->willReturn(5);
        $resource->expects($this->once())->method('getIdByRefreshToken')
            ->with(hash('sha256', 'raw-refresh'))->willReturn(5);
        $resource->expects($this->exactly(2))->method('load');

        $factory = $this->createMock(TokenFactory::class);
        $factory->method('create')->willReturn($this->createMock(Token::class));

        $repository = new TokenRepository($resource, $factory, new SecretHasher());
        $repository->getByAccessToken('raw-access');
        $repository->getByRefreshToken('raw-refresh');
    }

    /**
     * An unknown token is reported as missing.
     */
    public function testUnknownTokenThrows(): void
    {
        $resource = $this->createMock(TokenResource::class);
        $resource->method('getIdByAccessToken')->willReturn(null);

        $repository = new TokenRepository($resource, $this->createMock(TokenFactory::class), new SecretHasher());

        $this->expectException(NoSuchEntityException::class);
        $repository->getByAccessToken('nope');
    }

    /**
     * Authorization codes are looked up by their digest too.
     */
    public function testAuthCodeLookupUsesTheDigest(): void
    {
        $resource = $this->createMock(AuthCodeResource::class);
        $resource->expects($this->once())->method('getIdByCode')
            ->with(hash('sha256', 'raw-code'))->willReturn(9);

        $factory = $this->createMock(AuthCodeFactory::class);
        $factory->method('create')->willReturn($this->createMock(AuthCode::class));

        (new AuthCodeRepository($resource, $factory, new SecretHasher()))->getByCode('raw-code');
    }
}
