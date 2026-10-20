<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Tools\Product;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\Auth\AdminContext;
use Yu\McpServer\Model\Tools\Catalog\StorefrontScope;
use Yu\McpServer\Model\Tools\Product\SearchProducts;
use Yu\McpServer\Test\Unit\Model\Tools\Catalog\StorefrontScopeMockTrait;

class SearchProductsTest extends TestCase
{
    use StorefrontScopeMockTrait;

    /**
     * product_search is a public tool and must not require an ACL resource.
     */
    public function testRequiresNoAclResource(): void
    {
        $tool = new SearchProducts($this->createMock(CollectionFactory::class), $this->storefrontScope());

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

        $tool = new SearchProducts($collectionFactory, $this->storefrontScope());

        $result = $tool->execute(['query' => 'shirt']);

        $this->assertSame(
            [['sku' => 'SKU-1', 'name' => 'Test Product', 'price' => '19.99']],
            $result['products']
        );
    }

    /**
     * The customer view: anonymous searches must push the storefront filters (enabled,
     * visible in search, this website) into the collection, so hidden products never
     * reach the results.
     */
    public function testAnonymousSearchFiltersToStorefrontProducts(): void
    {
        $filters = [];
        $collection = $this->createMock(Collection::class);
        $collection->method('addAttributeToSelect')->willReturnSelf();
        $collection->method('addAttributeToFilter')->willReturnCallback(
            function ($attribute, $condition = null) use (&$filters, $collection) {
                if (is_string($attribute)) {
                    $filters[$attribute] = $condition;
                }
                return $collection;
            }
        );
        $collection->expects($this->once())->method('addWebsiteFilter')->with([1]);
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator([]));

        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        (new SearchProducts($collectionFactory, $this->storefrontScope()))->execute(['query' => 'shirt']);

        $this->assertSame(Status::STATUS_ENABLED, $filters['status'] ?? null);
        $this->assertSame(['in' => StorefrontScope::VISIBLE_IN_SEARCH], $filters['visibility'] ?? null);
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
                $this->assertNotContains($attribute, ['status', 'visibility'], 'admin view must not filter');
                return $collection;
            }
        );
        $collection->expects($this->never())->method('addWebsiteFilter');
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$product]));

        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $result = (new SearchProducts($collectionFactory, $this->storefrontScope()))->executeWithContext(
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
        $tool = new SearchProducts($collectionFactory, $this->storefrontScope());

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

        $tool = new SearchProducts($collectionFactory, $this->storefrontScope());

        $result = $tool->execute(['query' => 'nonexistent']);

        $this->assertSame([], $result['products']);
    }
}
