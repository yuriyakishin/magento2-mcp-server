<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Tools\Cms;

use Magento\Cms\Model\ResourceModel\Block\Collection as BlockCollection;
use Magento\Cms\Model\ResourceModel\Block\CollectionFactory as BlockCollectionFactory;
use Magento\Framework\DataObject;
use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\Tools\Cms\ListCmsBlocks;

class ListCmsBlocksTest extends TestCase
{
    /**
     * The listing includes inactive blocks — the tool must require the CMS block ACL.
     */
    public function testRequiresCmsBlockAclResource(): void
    {
        $tool = new ListCmsBlocks($this->createMock(BlockCollectionFactory::class));

        $this->assertSame('Magento_Cms::block', $tool->getRequiredAclResource());
        $this->assertSame('cms_block_list', $tool->getName());
    }

    /**
     * Malformed arguments must fail validation before any collection is built.
     */
    public function testThrowsOnInvalidArguments(): void
    {
        $factory = $this->createMock(BlockCollectionFactory::class);
        $factory->expects($this->never())->method('create');

        $tool = new ListCmsBlocks($factory);

        foreach ([['is_active' => 1], ['limit' => -1]] as $arguments) {
            try {
                $tool->execute($arguments);
                $this->fail('Expected InvalidArgumentException for: ' . json_encode($arguments));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Blocks come back with identifiers and active flags; total reflects the unpaged count.
     */
    public function testListsBlocks(): void
    {
        $blocks = [
            new DataObject([
                'id' => '7',
                'identifier' => 'footer-links',
                'title' => 'Footer links',
                'is_active' => '1',
                'creation_time' => '2026-01-01 00:00:00',
                'update_time' => '2026-06-01 00:00:00',
            ]),
        ];

        $collection = $this->createMock(BlockCollection::class);
        $collection->method('getIterator')->willReturn(new \ArrayIterator($blocks));
        $collection->method('getSize')->willReturn(1);

        $factory = $this->createMock(BlockCollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $result = (new ListCmsBlocks($factory))->execute([]);

        $this->assertSame(1, $result['total']);
        $this->assertSame('footer-links', $result['blocks'][0]['identifier']);
        $this->assertTrue($result['blocks'][0]['is_active']);
    }
}
