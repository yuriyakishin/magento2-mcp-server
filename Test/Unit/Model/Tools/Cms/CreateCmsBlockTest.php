<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Tools\Cms;

use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Api\Data\BlockSearchResultsInterface;
use Magento\Cms\Model\Block;
use Magento\Cms\Model\BlockFactory;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\Tools\Cms\CreateCmsBlock;
use Yu\McpServer\Model\WriteToolInterface;

class CreateCmsBlockTest extends TestCase
{
    /**
     * The tool must be a write tool gated by the documented Blocks ACL resource.
     */
    public function testDeclaresWriteToolContract(): void
    {
        $tool = new CreateCmsBlock(
            $this->createMock(BlockFactory::class),
            $this->createMock(BlockRepositoryInterface::class),
            $this->createMock(SearchCriteriaBuilder::class)
        );

        $this->assertInstanceOf(WriteToolInterface::class, $tool);
        $this->assertSame('Magento_Cms::block', $tool->getRequiredAclResource());
        $this->assertSame('cms_block_create', $tool->getName());
    }

    /**
     * Missing required fields and a malformed identifier must be rejected. Unlike pages,
     * block identifiers must not contain slashes.
     */
    public function testThrowsOnInvalidArguments(): void
    {
        $tool = new CreateCmsBlock(
            $this->createMock(BlockFactory::class),
            $this->createMock(BlockRepositoryInterface::class),
            $this->createMock(SearchCriteriaBuilder::class)
        );

        foreach (
            [
                [],
                ['identifier' => 'ok-block', 'title' => 'T'],
                ['identifier' => 'with/slash', 'title' => 'T', 'content' => '<p>x</p>'],
                ['identifier' => 'ok-block', 'title' => 'T', 'content' => '<p>x</p>', 'is_active' => 1],
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
     * An already-used identifier must fail hard, never turn into an implicit update.
     */
    public function testExistingIdentifierAbortsWithoutSaving(): void
    {
        $blockRepository = $this->mockRepositoryWithExistingCount(1);
        $blockRepository->expects($this->never())->method('save');

        $tool = new CreateCmsBlock(
            $this->createMock(BlockFactory::class),
            $blockRepository,
            $this->mockSearchCriteriaBuilder()
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already exists');

        $tool->execute(['identifier' => 'delivery-terms', 'title' => 'T', 'content' => '<p>x</p>']);
    }

    /**
     * A valid call creates the block INACTIVE by default, assigned to all store views.
     */
    public function testCreatesInactiveBlockByDefault(): void
    {
        $block = $this->createMock(Block::class);
        $block->expects($this->once())->method('setIdentifier')->with('promo-banner');
        $block->expects($this->once())->method('setTitle')->with('Promo Banner');
        $block->expects($this->once())->method('setContent')->with('<p>Sale</p>');
        $block->expects($this->once())->method('setIsActive')->with(false);
        $block->expects($this->once())->method('setData')->with('stores', [0]);
        $block->method('getId')->willReturn(5);
        $block->method('getIdentifier')->willReturn('promo-banner');
        $block->method('getTitle')->willReturn('Promo Banner');
        $block->method('isActive')->willReturn(false);

        $blockFactory = $this->createMock(BlockFactory::class);
        $blockFactory->method('create')->willReturn($block);

        $blockRepository = $this->mockRepositoryWithExistingCount(0);
        $blockRepository->expects($this->once())->method('save')->with($block)->willReturn($block);

        $tool = new CreateCmsBlock($blockFactory, $blockRepository, $this->mockSearchCriteriaBuilder());

        $result = $tool->execute([
            'identifier' => 'promo-banner',
            'title' => 'Promo Banner',
            'content' => '<p>Sale</p>',
        ]);

        $this->assertSame(
            ['id' => 5, 'identifier' => 'promo-banner', 'title' => 'Promo Banner', 'is_active' => false],
            $result['block']
        );
    }

    /**
     * Builds a block repository mock whose duplicate lookup reports the given match count.
     *
     * @return BlockRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private function mockRepositoryWithExistingCount(int $count): BlockRepositoryInterface
    {
        $searchResults = $this->createMock(BlockSearchResultsInterface::class);
        $searchResults->method('getTotalCount')->willReturn($count);
        $blockRepository = $this->createMock(BlockRepositoryInterface::class);
        $blockRepository->method('getList')->willReturn($searchResults);

        return $blockRepository;
    }

    /**
     * Builds a search criteria builder mock.
     */
    private function mockSearchCriteriaBuilder(): SearchCriteriaBuilder
    {
        $searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $searchCriteriaBuilder->method('create')->willReturn($this->createMock(SearchCriteria::class));

        return $searchCriteriaBuilder;
    }
}
