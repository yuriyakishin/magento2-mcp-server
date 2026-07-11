<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Tools\Cms;

use Magento\Cms\Api\Data\BlockInterface;
use Magento\Cms\Api\GetBlockByIdentifierInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\Tools\Cms\GetCmsBlock;

class GetCmsBlockTest extends TestCase
{
    /**
     * cms_block_get is a public tool and must not require an ACL resource.
     */
    public function testRequiresNoAclResource(): void
    {
        $tool = new GetCmsBlock(
            $this->createMock(GetBlockByIdentifierInterface::class),
            $this->createMock(StoreManagerInterface::class)
        );

        $this->assertNull($tool->getRequiredAclResource());
        $this->assertSame('cms_block_get', $tool->getName());
    }

    /**
     * A missing or empty "identifier" argument must fail validation.
     */
    public function testThrowsWhenIdentifierArgumentIsMissing(): void
    {
        $tool = new GetCmsBlock(
            $this->createMock(GetBlockByIdentifierInterface::class),
            $this->createMock(StoreManagerInterface::class)
        );

        $this->expectException(\InvalidArgumentException::class);

        $tool->execute([]);
    }

    /**
     * An unknown identifier is a business-logic error, reported as a RuntimeException.
     */
    public function testThrowsRuntimeExceptionForUnknownIdentifier(): void
    {
        $getBlockByIdentifier = $this->createMock(GetBlockByIdentifierInterface::class);
        $getBlockByIdentifier->method('execute')->willThrowException(
            NoSuchEntityException::singleField('identifier', 'missing')
        );

        $tool = new GetCmsBlock($getBlockByIdentifier, $this->mockStoreManager());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CMS block with identifier "missing" does not exist.');

        $tool->execute(['identifier' => 'missing']);
    }

    /**
     * An inactive block is not public content and must look exactly like a missing one.
     */
    public function testInactiveBlockIsReportedAsMissing(): void
    {
        $block = $this->createMock(BlockInterface::class);
        $block->method('isActive')->willReturn(false);

        $getBlockByIdentifier = $this->createMock(GetBlockByIdentifierInterface::class);
        $getBlockByIdentifier->method('execute')->willReturn($block);

        $tool = new GetCmsBlock($getBlockByIdentifier, $this->mockStoreManager());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CMS block with identifier "draft" does not exist.');

        $tool->execute(['identifier' => 'draft']);
    }

    /**
     * An active block should be returned with its content.
     */
    public function testReturnsActiveBlock(): void
    {
        $block = $this->createMock(BlockInterface::class);
        $block->method('isActive')->willReturn(true);
        $block->method('getId')->willReturn('4');
        $block->method('getIdentifier')->willReturn('delivery-terms');
        $block->method('getTitle')->willReturn('Delivery Terms');
        $block->method('getContent')->willReturn('<p>Free from 1000 EUR</p>');
        $block->method('getUpdateTime')->willReturn('2026-07-07 10:00:00');

        $getBlockByIdentifier = $this->createMock(GetBlockByIdentifierInterface::class);
        $getBlockByIdentifier->method('execute')->with('delivery-terms', 1)->willReturn($block);

        $tool = new GetCmsBlock($getBlockByIdentifier, $this->mockStoreManager());

        $result = $tool->execute(['identifier' => 'delivery-terms']);

        $this->assertSame(4, $result['block']['id']);
        $this->assertSame('<p>Free from 1000 EUR</p>', $result['block']['content']);
    }

    /**
     * Builds a store manager mock for store id 1.
     */
    private function mockStoreManager(): StoreManagerInterface
    {
        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return $storeManager;
    }
}
