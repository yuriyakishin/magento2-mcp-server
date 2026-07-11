<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Tools\Cms;

use Magento\Cms\Model\ResourceModel\Page\Collection as PageCollection;
use Magento\Cms\Model\ResourceModel\Page\CollectionFactory as PageCollectionFactory;
use Magento\Framework\DataObject;
use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\Tools\Cms\ListCmsPages;

class ListCmsPagesTest extends TestCase
{
    /**
     * The listing includes inactive drafts — the tool must require the CMS page ACL.
     */
    public function testRequiresCmsPageAclResource(): void
    {
        $tool = new ListCmsPages($this->createMock(PageCollectionFactory::class));

        $this->assertSame('Magento_Cms::page', $tool->getRequiredAclResource());
        $this->assertSame('cms_page_list', $tool->getName());
    }

    /**
     * Malformed arguments must fail validation before any collection is built.
     */
    public function testThrowsOnInvalidArguments(): void
    {
        $factory = $this->createMock(PageCollectionFactory::class);
        $factory->expects($this->never())->method('create');

        $tool = new ListCmsPages($factory);

        foreach ([['is_active' => 'yes'], ['limit' => 0]] as $arguments) {
            try {
                $tool->execute($arguments);
                $this->fail('Expected InvalidArgumentException for: ' . json_encode($arguments));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Pages come back with identifiers and active flags; total reflects the unpaged count.
     */
    public function testListsPages(): void
    {
        $pages = [
            new DataObject([
                'id' => '1',
                'identifier' => 'about-us',
                'title' => 'About us',
                'is_active' => '1',
                'creation_time' => '2026-01-01 00:00:00',
                'update_time' => '2026-06-01 00:00:00',
            ]),
            new DataObject([
                'id' => '2',
                'identifier' => 'draft-promo',
                'title' => 'Promo draft',
                'is_active' => '0',
                'creation_time' => '2026-05-01 00:00:00',
                'update_time' => '2026-05-02 00:00:00',
            ]),
        ];

        $collection = $this->createMock(PageCollection::class);
        $collection->method('getIterator')->willReturn(new \ArrayIterator($pages));
        $collection->method('getSize')->willReturn(2);
        $collection->expects($this->never())->method('addFieldToFilter');

        $factory = $this->createMock(PageCollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $result = (new ListCmsPages($factory))->execute([]);

        $this->assertSame(2, $result['total']);
        $this->assertSame('about-us', $result['pages'][0]['identifier']);
        $this->assertTrue($result['pages'][0]['is_active']);
        $this->assertFalse($result['pages'][1]['is_active']);
    }

    /**
     * The is_active argument becomes a collection filter.
     */
    public function testFiltersByIsActive(): void
    {
        $collection = $this->createMock(PageCollection::class);
        $collection->method('getIterator')->willReturn(new \ArrayIterator([]));
        $collection->method('getSize')->willReturn(0);
        $collection->expects($this->once())
            ->method('addFieldToFilter')
            ->with('is_active', 0);

        $factory = $this->createMock(PageCollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $result = (new ListCmsPages($factory))->execute(['is_active' => false]);

        $this->assertSame(0, $result['total']);
        $this->assertSame([], $result['pages']);
    }
}
