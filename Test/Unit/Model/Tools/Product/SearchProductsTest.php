<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Tools\Product;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\Auth\AdminContext;
use Yu\McpServer\Model\Tools\Product\SearchProducts;

class SearchProductsTest extends TestCase
{
    /**
     * product_search is a public tool and must not require an ACL resource.
     */
    public function testRequiresNoAclResource(): void
    {
        $tool = new SearchProducts($this->createMock(CollectionFactory::class));

        $this->assertNull($tool->getRequiredAclResource());
    }

    /**
     * A valid query should return the matching products from the collection.
     */
    public function testReturnsProductsForValidQuery(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getSku')->willReturn('SKU-1');
        $product->method('getName')->willReturn('Test Product');
        $product->method('getPrice')->willReturn('19.99');

        $collection = $this->createMock(Collection::class);
        $collection->method('addAttributeToSelect')->willReturnSelf();
        $collection->method('addAttributeToFilter')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$product]));

        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $tool = new SearchProducts($collectionFactory);

        $result = $tool->execute(['query' => 'shirt']);

        $this->assertSame(
            [['sku' => 'SKU-1', 'name' => 'Test Product', 'price' => '19.99']],
            $result['products']
        );
    }

    /**
     * The customer view: anonymous searches must push an enabled-only status filter into
     * the collection, so disabled products never reach the results.
     */
    public function testAnonymousSearchFiltersToEnabledProducts(): void
    {
        $statusFiltered = false;
        $collection = $this->createMock(Collection::class);
        $collection->method('addAttributeToSelect')->willReturnSelf();
        $collection->method('addAttributeToFilter')->willReturnCallback(
            function ($attribute, $condition = null) use (&$statusFiltered, $collection) {
                if ($attribute === 'status' && $condition === Status::STATUS_ENABLED) {
                    $statusFiltered = true;
                }
                return $collection;
            }
        );
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator([]));

        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        (new SearchProducts($collectionFactory))->execute(['query' => 'shirt']);

        $this->assertTrue($statusFiltered, 'anonymous search must filter status = enabled');
    }

    /**
     * The admin view: with the catalog ACL resource no status filter is applied, and each
     * row reports its status so disabled products are recognizable.
     */
    public function testAdminSearchIncludesDisabledProductsWithStatus(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getSku')->willReturn('HIDDEN-1');
        $product->method('getName')->willReturn('Hidden Product');
        $product->method('getPrice')->willReturn('9.99');
        $product->method('getStatus')->willReturn(Status::STATUS_DISABLED);

        $collection = $this->createMock(Collection::class);
        $collection->method('addAttributeToSelect')->willReturnSelf();
        $collection->method('addAttributeToFilter')->willReturnCallback(
            function ($attribute) use ($collection) {
                $this->assertNotSame('status', $attribute, 'admin view must not filter by status');
                return $collection;
            }
        );
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$product]));

        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $result = (new SearchProducts($collectionFactory))->executeWithContext(
            ['query' => 'hidden'],
            new AdminContext(1, ['Magento_Catalog::products'])
        );

        $this->assertSame(
            [['sku' => 'HIDDEN-1', 'name' => 'Hidden Product', 'price' => '9.99', 'status' => 'disabled']],
            $result['products']
        );
    }

    /**
     * A missing required "query" argument must fail validation.
     */
    public function testThrowsWhenQueryArgumentIsMissing(): void
    {
        $collectionFactory = $this->createMock(CollectionFactory::class);
        $tool = new SearchProducts($collectionFactory);

        $this->expectException(\InvalidArgumentException::class);

        $tool->execute([]);
    }

    /**
     * An empty collection should produce an empty product list, not an error.
     */
    public function testReturnsEmptyListWhenNothingFound(): void
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('addAttributeToSelect')->willReturnSelf();
        $collection->method('addAttributeToFilter')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator([]));

        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $tool = new SearchProducts($collectionFactory);

        $result = $tool->execute(['query' => 'nonexistent']);

        $this->assertSame([], $result['products']);
    }
}
