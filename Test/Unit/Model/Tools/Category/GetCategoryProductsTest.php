<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Tools\Category;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\Tools\Category\GetCategoryProducts;

class GetCategoryProductsTest extends TestCase
{
    /**
     * category_products is a public tool and must not require an ACL resource.
     */
    public function testRequiresNoAclResource(): void
    {
        $tool = new GetCategoryProducts(
            $this->createMock(CategoryRepositoryInterface::class),
            $this->createMock(CollectionFactory::class)
        );

        $this->assertNull($tool->getRequiredAclResource());
        $this->assertSame('category_products', $tool->getName());
    }

    /**
     * A missing or non-positive "category_id" argument must fail validation.
     */
    public function testThrowsOnInvalidArguments(): void
    {
        $tool = new GetCategoryProducts(
            $this->createMock(CategoryRepositoryInterface::class),
            $this->createMock(CollectionFactory::class)
        );

        foreach ([[], ['category_id' => 0], ['category_id' => 'shoes'], ['category_id' => 3, 'limit' => 0]] as $arguments) {
            try {
                $tool->execute($arguments);
                $this->fail('Expected InvalidArgumentException for: ' . json_encode($arguments));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * An unknown category is a business-logic error, reported as a RuntimeException.
     */
    public function testThrowsRuntimeExceptionForUnknownCategory(): void
    {
        $categoryRepository = $this->createMock(CategoryRepositoryInterface::class);
        $categoryRepository->method('get')->willThrowException(
            NoSuchEntityException::singleField('id', 99)
        );

        $tool = new GetCategoryProducts(
            $categoryRepository,
            $this->createMock(CollectionFactory::class)
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Category with ID 99 does not exist.');

        $tool->execute(['category_id' => 99]);
    }

    /**
     * A valid category returns its products filtered by that category.
     */
    public function testReturnsCategoryProducts(): void
    {
        $category = $this->createMock(CategoryInterface::class);
        $category->method('getName')->willReturn('Shoes');
        $categoryRepository = $this->createMock(CategoryRepositoryInterface::class);
        $categoryRepository->method('get')->with(3)->willReturn($category);

        $product = $this->createMock(Product::class);
        $product->method('getSku')->willReturn('SHOE-1');
        $product->method('getName')->willReturn('Runner');
        $product->method('getPrice')->willReturn(59.0);
        $product->method('getStatus')->willReturn(Status::STATUS_ENABLED);

        $collection = $this->createMock(Collection::class);
        $collection->method('addAttributeToSelect')->willReturnSelf();
        $collection->expects($this->once())->method('addCategoriesFilter')->with(['in' => [3]]);
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$product]));

        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $tool = new GetCategoryProducts($categoryRepository, $collectionFactory);

        $result = $tool->execute(['category_id' => 3]);

        $this->assertSame(['id' => 3, 'name' => 'Shoes'], $result['category']);
        $this->assertSame(1, $result['count']);
        $this->assertSame(
            ['sku' => 'SHOE-1', 'name' => 'Runner', 'price' => 59.0, 'status' => 'enabled'],
            $result['products'][0]
        );
    }

    /**
     * An empty category yields an empty product list, not an error.
     */
    public function testReturnsEmptyListForEmptyCategory(): void
    {
        $category = $this->createMock(CategoryInterface::class);
        $category->method('getName')->willReturn('Empty');
        $categoryRepository = $this->createMock(CategoryRepositoryInterface::class);
        $categoryRepository->method('get')->willReturn($category);

        $collection = $this->createMock(Collection::class);
        $collection->method('addAttributeToSelect')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator([]));

        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $tool = new GetCategoryProducts($categoryRepository, $collectionFactory);

        $result = $tool->execute(['category_id' => 5]);

        $this->assertSame([], $result['products']);
        $this->assertSame(0, $result['count']);
    }
}
