<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Tools\Cms;

use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Api\Data\BlockInterface;
use Magento\Cms\Api\GetBlockByIdentifierInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\Tools\Cms\UpdateCmsBlock;
use Yu\McpServer\Model\WriteToolInterface;

class UpdateCmsBlockTest extends TestCase
{
    /**
     * The tool must be a write tool gated by the documented Blocks ACL resource.
     */
    public function testDeclaresWriteToolContract(): void
    {
        $tool = new UpdateCmsBlock(
            $this->createMock(GetBlockByIdentifierInterface::class),
            $this->createMock(BlockRepositoryInterface::class)
        );

        $this->assertInstanceOf(WriteToolInterface::class, $tool);
        $this->assertSame('Magento_Cms::block', $tool->getRequiredAclResource());
        $this->assertSame('cms_block_update', $tool->getName());
    }

    /**
     * A call with no updatable field or an empty content must be rejected before the
     * block is even loaded.
     */
    public function testThrowsOnInvalidArguments(): void
    {
        $getBlockByIdentifier = $this->createMock(GetBlockByIdentifierInterface::class);
        $getBlockByIdentifier->expects($this->never())->method('execute');

        $tool = new UpdateCmsBlock(
            $getBlockByIdentifier,
            $this->createMock(BlockRepositoryInterface::class)
        );

        foreach (
            [
                [],
                ['identifier' => 'delivery-terms'],
                ['identifier' => 'delivery-terms', 'content' => ''],
                ['identifier' => 'delivery-terms', 'is_active' => 1],
            ] as $arguments
        ) {
            try {
                $tool->execute($arguments);
                $this->fail('Expected InvalidArgumentException for: ' . json_encode($arguments));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * An unknown identifier must fail hard, never turn into an implicit create.
     */
    public function testUnknownIdentifierAbortsWithoutSaving(): void
    {
        $getBlockByIdentifier = $this->createMock(GetBlockByIdentifierInterface::class);
        $getBlockByIdentifier->method('execute')->willThrowException(
            NoSuchEntityException::singleField('identifier', 'missing')
        );

        $blockRepository = $this->createMock(BlockRepositoryInterface::class);
        $blockRepository->expects($this->never())->method('save');

        $tool = new UpdateCmsBlock($getBlockByIdentifier, $blockRepository);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CMS block with identifier "missing" does not exist.');

        $tool->execute(['identifier' => 'missing', 'content' => '<p>x</p>']);
    }

    /**
     * Only the provided fields are changed; everything else stays untouched.
     */
    public function testUpdatesOnlyProvidedFields(): void
    {
        $block = $this->createMock(BlockInterface::class);
        $block->expects($this->once())->method('setContent')->with('<p>Free from 2000 EUR</p>');
        $block->expects($this->never())->method('setTitle');
        $block->expects($this->never())->method('setIsActive');
        $block->expects($this->never())->method('setIdentifier');
        $block->method('getId')->willReturn('4');
        $block->method('getIdentifier')->willReturn('delivery-terms');
        $block->method('getTitle')->willReturn('Delivery Terms');
        $block->method('isActive')->willReturn(true);

        $getBlockByIdentifier = $this->createMock(GetBlockByIdentifierInterface::class);
        $getBlockByIdentifier->method('execute')->with('delivery-terms', 0)->willReturn($block);

        $blockRepository = $this->createMock(BlockRepositoryInterface::class);
        $blockRepository->expects($this->once())->method('save')->with($block)->willReturn($block);

        $tool = new UpdateCmsBlock($getBlockByIdentifier, $blockRepository);

        $result = $tool->execute([
            'identifier' => 'delivery-terms',
            'content' => '<p>Free from 2000 EUR</p>',
        ]);

        $this->assertSame(4, $result['block']['id']);
        $this->assertSame(['content'], $result['block']['updated_fields']);
    }
}
