<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Tools\Category;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\ResourceModel\Category\Collection;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\Tools\Category\GetCategories;

class GetCategoriesTest extends TestCase
{
    /**
     * category_tree is a public tool and must not require an ACL resource.
     */
    public function testRequiresNoAclResource(): void
    {
        $tool = new GetCategories(
            $this->createMock(CollectionFactory::class),
            $this->createMock(StoreManagerInterface::class)
        );

        $this->assertNull($tool->getRequiredAclResource());
        $this->assertSame('category_tree', $tool->getName());
    }

    /**
     * By default only active categories are returned, scoped to the store's root subtree.
     */
    public function testReturnsActiveCategoriesByDefault(): void
    {
        $category = $this->mockCategory(3, 2, 'Shoes', 2, 'shoes', true);

        $collection = $this->mockCollection([$category]);
        $collection->expects($this->once())->method('addAttributeToFilter')->with('is_active', 1);
        $collection->expects($this->once())->method('addFieldToFilter')->with(
            'path',
            [
                ['eq' => '1/2'],
                ['like' => '1/2/%'],
            ]
        );

        $tool = new GetCategories($this->mockCollectionFactory($collection), $this->mockStoreManager(2));

        $result = $tool->execute([]);

        $this->assertSame(2, $result['root_category_id']);
        $this->assertSame(1, $result['count']);
        $this->assertSame(
            [
                'id' => 3,
                'parent_id' => 2,
                'name' => 'Shoes',
                'level' => 2,
                'url_key' => 'shoes',
                'is_active' => true,
            ],
            $result['categories'][0]
        );
    }

    /**
     * include_inactive = true must skip the is_active filter.
     */
    public function testIncludeInactiveSkipsActivityFilter(): void
    {
        $collection = $this->mockCollection([]);
        $collection->expects($this->never())->method('addAttributeToFilter');

        $tool = new GetCategories($this->mockCollectionFactory($collection), $this->mockStoreManager(2));

        $result = $tool->execute(['include_inactive' => true]);

        $this->assertSame([], $result['categories']);
        $this->assertSame(0, $result['count']);
    }

    /**
     * Builds a category mock with the fields the tool reads.
     */
    private function mockCategory(
        int $id,
        int $parentId,
        string $name,
        int $level,
        string $urlKey,
        bool $isActive
    ): Category {
        $category = $this->createMock(Category::class);
        $category->method('getId')->willReturn($id);
        $category->method('getParentId')->willReturn($parentId);
        $category->method('getName')->willReturn($name);
        $category->method('getLevel')->willReturn($level);
        $category->method('getData')->willReturnMap([['url_key', null, $urlKey]]);
        $category->method('getIsActive')->willReturn($isActive);

        return $category;
    }

    /**
     * Builds a category collection mock iterating over the given categories.
     *
     * @param Category[] $categories
     * @return Collection&\PHPUnit\Framework\MockObject\MockObject
     */
    private function mockCollection(array $categories): Collection
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('addAttributeToSelect')->willReturnSelf();
        $collection->method('addAttributeToSort')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($categories));

        return $collection;
    }

    /**
     * Wraps a collection mock into its factory mock.
     */
    private function mockCollectionFactory(Collection $collection): CollectionFactory
    {
        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        return $collectionFactory;
    }

    /**
     * Builds a store manager mock whose store reports the given root category id.
     */
    private function mockStoreManager(int $rootCategoryId): StoreManagerInterface
    {
        $store = $this->createMock(Store::class);
        $store->method('getRootCategoryId')->willReturn($rootCategoryId);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return $storeManager;
    }
}
