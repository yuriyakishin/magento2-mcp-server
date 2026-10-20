<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Tools\Catalog;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\Tools\Catalog\StorefrontScope;

class StorefrontScopeTest extends TestCase
{
    use StorefrontScopeMockTrait;

    /**
     * An enabled product of this website with a page of its own is visible.
     */
    public function testVisibleProduct(): void
    {
        $product = $this->product(Status::STATUS_ENABLED, [1], Visibility::VISIBILITY_BOTH);

        $this->assertTrue(
            $this->storefrontScope()->isProductVisible($product, StorefrontScope::VISIBLE_INDIVIDUALLY)
        );
    }

    /**
     * Disabled, other-website and not-visible-individually products are all hidden.
     */
    public function testHiddenProducts(): void
    {
        $scope = $this->storefrontScope();

        $this->assertFalse($scope->isProductVisible(
            $this->product(Status::STATUS_DISABLED, [1], Visibility::VISIBILITY_BOTH),
            StorefrontScope::VISIBLE_INDIVIDUALLY
        ));
        $this->assertFalse($scope->isProductVisible(
            $this->product(Status::STATUS_ENABLED, [2], Visibility::VISIBILITY_BOTH),
            StorefrontScope::VISIBLE_INDIVIDUALLY
        ));
        $this->assertFalse($scope->isProductVisible(
            $this->product(Status::STATUS_ENABLED, [1], Visibility::VISIBILITY_NOT_VISIBLE),
            StorefrontScope::VISIBLE_INDIVIDUALLY
        ));
    }

    /**
     * A null visibility list skips the visibility check (variants of configurables).
     */
    public function testNullVisibilitySkipsVisibilityCheck(): void
    {
        $product = $this->product(Status::STATUS_ENABLED, [1], Visibility::VISIBILITY_NOT_VISIBLE);

        $this->assertTrue($this->storefrontScope()->isProductVisible($product, null));
    }

    /**
     * Only active categories strictly inside this store's root are visible.
     */
    public function testCategoryVisibility(): void
    {
        $scope = $this->storefrontScope(1, 2);

        $this->assertTrue($scope->isCategoryVisible($this->category(true, '1/2/5')));
        $this->assertFalse($scope->isCategoryVisible($this->category(false, '1/2/5')));
        $this->assertFalse($scope->isCategoryVisible($this->category(true, '1/2')));
        $this->assertFalse($scope->isCategoryVisible($this->category(true, '1/20/7')));
    }

    /**
     * The collection filter applies status, visibility and website restrictions.
     */
    public function testFilterProductCollection(): void
    {
        $filters = [];
        $collection = $this->createMock(Collection::class);
        $collection->method('addAttributeToFilter')->willReturnCallback(
            function ($attribute, $condition = null) use (&$filters, $collection) {
                $filters[$attribute] = $condition;
                return $collection;
            }
        );
        $collection->expects($this->once())->method('addWebsiteFilter')->with([3]);

        $this->storefrontScope(3)->filterProductCollection($collection, StorefrontScope::VISIBLE_IN_SEARCH);

        $this->assertSame(Status::STATUS_ENABLED, $filters['status']);
        $this->assertSame(['in' => StorefrontScope::VISIBLE_IN_SEARCH], $filters['visibility']);
    }

    /**
     * @param int $status Product status.
     * @param int[] $websiteIds Websites the product is assigned to.
     * @param int $visibility Product visibility.
     */
    private function product(int $status, array $websiteIds, int $visibility): Product
    {
        $product = $this->createMock(Product::class);
        $product->method('getStatus')->willReturn($status);
        $product->method('getWebsiteIds')->willReturn($websiteIds);
        $product->method('getVisibility')->willReturn($visibility);

        return $product;
    }

    /**
     * @param bool $isActive Category active flag.
     * @param string $path Category path.
     */
    private function category(bool $isActive, string $path): Category
    {
        $category = $this->createMock(Category::class);
        $category->method('getIsActive')->willReturn($isActive);
        $category->method('getPath')->willReturn($path);

        return $category;
    }
}
