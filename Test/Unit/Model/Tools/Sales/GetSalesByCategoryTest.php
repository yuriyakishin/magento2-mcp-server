<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Tools\Sales;

use Magento\Catalog\Model\ResourceModel\Category\Collection as CategoryCollection;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\DataObject;
use Magento\Sales\Model\ResourceModel\Order\Item\Collection as OrderItemCollection;
use Magento\Sales\Model\ResourceModel\Order\Item\CollectionFactory as OrderItemCollectionFactory;
use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\Tools\Sales\GetSalesByCategory;

class GetSalesByCategoryTest extends TestCase
{
    /**
     * Per-category sales are order data — the tool must require the Sales ACL resource.
     */
    public function testRequiresSalesAclResource(): void
    {
        $tool = new GetSalesByCategory(
            $this->createMock(OrderItemCollectionFactory::class),
            $this->createMock(ProductCollectionFactory::class),
            $this->createMock(CategoryCollectionFactory::class)
        );

        $this->assertSame('Magento_Sales::sales', $tool->getRequiredAclResource());
        $this->assertSame('sales_by_category', $tool->getName());
    }

    /**
     * created_from is mandatory; malformed arguments must fail validation before any
     * collection is built.
     */
    public function testThrowsOnInvalidArguments(): void
    {
        $itemFactory = $this->createMock(OrderItemCollectionFactory::class);
        $itemFactory->expects($this->never())->method('create');

        $tool = new GetSalesByCategory(
            $itemFactory,
            $this->createMock(ProductCollectionFactory::class),
            $this->createMock(CategoryCollectionFactory::class)
        );

        foreach ([[], ['created_from' => 'nope'], ['created_from' => '2026-06-01', 'limit' => -5]] as $arguments) {
            try {
                $tool->execute($arguments);
                $this->fail('Expected InvalidArgumentException for: ' . json_encode($arguments));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Items map onto current category assignments (a product in two categories counts in
     * both), categories sort by revenue, deleted products land in "(no category)".
     */
    public function testGroupsRevenueByCategory(): void
    {
        $items = [
            new DataObject(['product_id' => '10', 'qty_ordered' => '2', 'row_total' => '200.00']),
            new DataObject(['product_id' => '11', 'qty_ordered' => '1', 'row_total' => '500.00']),
            new DataObject(['product_id' => '99', 'qty_ordered' => '1', 'row_total' => '50.00']),
        ];
        // DataObject::getId() reads the "id" key (the real models map it to entity_id).
        $products = [
            new DataObject(['id' => '10', 'category_ids' => ['3', '4']]),
            new DataObject(['id' => '11', 'category_ids' => ['4']]),
        ];
        $categories = [
            new DataObject(['id' => '3', 'name' => 'Tops']),
            new DataObject(['id' => '4', 'name' => 'Sale']),
        ];

        $tool = new GetSalesByCategory(
            $this->itemFactoryMock($items),
            $this->productFactoryMock($products),
            $this->categoryFactoryMock($categories)
        );

        $result = $tool->execute(['created_from' => '2026-06-01']);

        $this->assertSame('Sale', $result['categories'][0]['name']);
        $this->assertSame(700.0, $result['categories'][0]['revenue']);
        $this->assertSame(2, $result['categories'][0]['products']);
        $this->assertSame('Tops', $result['categories'][1]['name']);
        $this->assertSame(200.0, $result['categories'][1]['revenue']);
        $this->assertSame('(no category)', $result['categories'][2]['name']);
        $this->assertSame(50.0, $result['categories'][2]['revenue']);
    }

    /**
     * No orders in the period means an empty category list, not an error (and no
     * product/category lookups at all).
     */
    public function testEmptyPeriod(): void
    {
        $productFactory = $this->createMock(ProductCollectionFactory::class);
        $productFactory->expects($this->never())->method('create');

        $tool = new GetSalesByCategory(
            $this->itemFactoryMock([]),
            $productFactory,
            $this->createMock(CategoryCollectionFactory::class)
        );

        $result = $tool->execute(['created_from' => '2026-06-01']);

        $this->assertSame([], $result['categories']);
    }

    /**
     * Builds an order item collection factory whose collection iterates the given rows.
     *
     * @param DataObject[] $items
     */
    private function itemFactoryMock(array $items): OrderItemCollectionFactory
    {
        $collection = $this->createMock(OrderItemCollection::class);
        $collection->method('getIterator')->willReturn(new \ArrayIterator($items));

        $factory = $this->createMock(OrderItemCollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        return $factory;
    }

    /**
     * Builds a product collection factory whose rows expose getCategoryIds().
     *
     * @param DataObject[] $products
     */
    private function productFactoryMock(array $products): ProductCollectionFactory
    {
        $collection = $this->createMock(ProductCollection::class);
        $collection->method('getIterator')->willReturn(new \ArrayIterator($products));

        $factory = $this->createMock(ProductCollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        return $factory;
    }

    /**
     * Builds a category collection factory whose collection iterates the given rows.
     *
     * @param DataObject[] $categories
     */
    private function categoryFactoryMock(array $categories): CategoryCollectionFactory
    {
        $collection = $this->createMock(CategoryCollection::class);
        $collection->method('getIterator')->willReturn(new \ArrayIterator($categories));

        $factory = $this->createMock(CategoryCollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        return $factory;
    }
}
