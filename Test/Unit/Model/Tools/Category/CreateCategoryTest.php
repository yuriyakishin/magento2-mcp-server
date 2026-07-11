<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Tools\Category;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\CategoryFactory;
use Magento\Catalog\Model\ResourceModel\Category\Collection as CategoryCollection;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\Tools\Category\CreateCategory;
use Yu\McpServer\Model\WriteToolInterface;

class CreateCategoryTest extends TestCase
{
    /**
     * The tool must be a write tool gated by the documented Categories ACL resource.
     */
    public function testDeclaresWriteToolContract(): void
    {
        $tool = $this->makeTool();

        $this->assertInstanceOf(WriteToolInterface::class, $tool);
        $this->assertSame('Magento_Catalog::categories', $tool->getRequiredAclResource());
        $this->assertSame('category_create', $tool->getName());
    }

    /**
     * A missing or empty "name" must be rejected before anything is loaded or saved.
     */
    public function testThrowsWhenNameMissing(): void
    {
        $tool = $this->makeTool();

        $this->expectException(\InvalidArgumentException::class);

        $tool->execute([]);
    }

    /**
     * A nonexistent parent category must fail with a clear error.
     */
    public function testThrowsWhenParentDoesNotExist(): void
    {
        $categoryRepository = $this->createMock(CategoryRepositoryInterface::class);
        $categoryRepository->method('get')->willThrowException(
            NoSuchEntityException::singleField('id', 999)
        );

        $tool = $this->makeTool(categoryRepository: $categoryRepository);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Parent category with ID 999 does not exist.');

        $tool->execute(['name' => 'Shoes', 'parent_id' => 999]);
    }

    /**
     * A same-name sibling must be rejected instead of silently creating a duplicate.
     */
    public function testThrowsWhenDuplicateNameUnderSameParent(): void
    {
        $existing = $this->createMock(Category::class);
        $existing->method('getId')->willReturn(42);

        $collection = $this->mockCollection(size: 1, firstItem: $existing);

        $tool = $this->makeTool(collection: $collection);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already exists');

        $tool->execute(['name' => 'Shoes', 'parent_id' => 2]);
    }

    /**
     * Valid arguments must produce a saved category and return its identifiers.
     */
    public function testCreatesCategoryWithDefaults(): void
    {
        $category = $this->createMock(Category::class);
        $category->expects($this->once())->method('setName')->with('Shoes');
        $category->expects($this->once())->method('setParentId')->with(2);
        $category->expects($this->once())->method('setIsActive')->with(true);

        $categoryFactory = $this->createMock(CategoryFactory::class);
        $categoryFactory->method('create')->willReturn($category);

        $saved = $this->createMock(Category::class);
        $saved->method('getId')->willReturn(57);
        $saved->method('getName')->willReturn('Shoes');
        $saved->method('getParentId')->willReturn(2);
        $saved->method('getIsActive')->willReturn(true);
        $saved->method('getPath')->willReturn('1/2/57');

        $categoryRepository = $this->createMock(CategoryRepositoryInterface::class);
        $categoryRepository->method('get')->willReturn($this->createMock(Category::class));
        $categoryRepository->expects($this->once())->method('save')->with($category)->willReturn($saved);

        $tool = $this->makeTool(
            categoryFactory: $categoryFactory,
            categoryRepository: $categoryRepository
        );

        $result = $tool->execute(['name' => 'Shoes']);

        $this->assertSame(57, $result['category']['id']);
        $this->assertSame('Shoes', $result['category']['name']);
        $this->assertSame(2, $result['category']['parent_id']);
        $this->assertTrue($result['category']['is_active']);
    }

    /**
     * Builds the tool with sensible default mocks, overridable per test.
     */
    private function makeTool(
        ?CategoryFactory $categoryFactory = null,
        ?CategoryRepositoryInterface $categoryRepository = null,
        ?CategoryCollection $collection = null
    ): CreateCategory {
        if ($categoryRepository === null) {
            $categoryRepository = $this->createMock(CategoryRepositoryInterface::class);
            $categoryRepository->method('get')->willReturn($this->createMock(Category::class));
        }

        $collectionFactory = $this->createMock(CategoryCollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection ?? $this->mockCollection(size: 0));

        // Store::getRootCategoryId() is a declared method, so a plain mock works here.
        $store = $this->createMock(Store::class);
        $store->method('getRootCategoryId')->willReturn(2);

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return new CreateCategory(
            $categoryFactory ?? $this->createMock(CategoryFactory::class),
            $categoryRepository,
            $collectionFactory,
            $storeManager
        );
    }

    /**
     * Builds a duplicate-check collection mock returning the given size/first item.
     */
    private function mockCollection(int $size, ?MockObject $firstItem = null): CategoryCollection&MockObject
    {
        $collection = $this->createMock(CategoryCollection::class);
        $collection->method('addAttributeToFilter')->willReturnSelf();
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getSize')->willReturn($size);
        if ($firstItem !== null) {
            $collection->method('getFirstItem')->willReturn($firstItem);
        }

        return $collection;
    }
}
