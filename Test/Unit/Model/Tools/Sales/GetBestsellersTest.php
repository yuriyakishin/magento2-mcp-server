<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Tools\Sales;

use Magento\Framework\DataObject;
use Magento\Sales\Model\ResourceModel\Order\Item\Collection as OrderItemCollection;
use Magento\Sales\Model\ResourceModel\Order\Item\CollectionFactory as OrderItemCollectionFactory;
use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\Tools\Sales\GetBestsellers;

class GetBestsellersTest extends TestCase
{
    /**
     * Per-product sales are order data — the tool must require the Sales ACL resource.
     */
    public function testRequiresSalesAclResource(): void
    {
        $tool = new GetBestsellers($this->createMock(OrderItemCollectionFactory::class));

        $this->assertSame('Magento_Sales::sales', $tool->getRequiredAclResource());
        $this->assertSame('sales_bestsellers', $tool->getName());
    }

    /**
     * created_from is mandatory and malformed arguments must fail validation before any
     * collection is built.
     */
    public function testThrowsOnInvalidArguments(): void
    {
        $factory = $this->createMock(OrderItemCollectionFactory::class);
        $factory->expects($this->never())->method('create');

        $tool = new GetBestsellers($factory);

        $invalid = [
            [],
            ['created_from' => 'June 2026'],
            ['created_from' => '2026-06-01', 'sort_by' => 'price'],
            ['created_from' => '2026-06-01', 'limit' => 0],
        ];
        foreach ($invalid as $arguments) {
            try {
                $tool->execute($arguments);
                $this->fail('Expected InvalidArgumentException for: ' . json_encode($arguments));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Item rows aggregate per SKU with distinct-order counts, default sort is quantity.
     */
    public function testAggregatesAndSortsByQty(): void
    {
        $items = [
            new DataObject(['sku' => 'WS02', 'name' => 'Top', 'qty_ordered' => '2', 'row_total' => '400.00']),
            new DataObject(['sku' => 'WS02', 'name' => 'Top', 'qty_ordered' => '3', 'row_total' => '600.00']),
            new DataObject(['sku' => 'WP01', 'name' => 'Pant', 'qty_ordered' => '1', 'row_total' => '5000.00']),
        ];

        $result = (new GetBestsellers($this->factoryMock($items)))
            ->execute(['created_from' => '2026-06-01', 'created_to' => '2026-06-30']);

        $this->assertSame('WS02', $result['products'][0]['sku']);
        $this->assertSame(5.0, $result['products'][0]['qty']);
        $this->assertSame(1000.0, $result['products'][0]['revenue']);
        $this->assertSame(2, $result['products'][0]['orders']);
        $this->assertSame('WP01', $result['products'][1]['sku']);
        $this->assertArrayNotHasKey('truncated', $result);
    }

    /**
     * sort_by = revenue flips the order for the same rows.
     */
    public function testSortsByRevenue(): void
    {
        $items = [
            new DataObject(['sku' => 'WS02', 'name' => 'Top', 'qty_ordered' => '5', 'row_total' => '1000.00']),
            new DataObject(['sku' => 'WP01', 'name' => 'Pant', 'qty_ordered' => '1', 'row_total' => '5000.00']),
        ];

        $result = (new GetBestsellers($this->factoryMock($items)))
            ->execute(['created_from' => '2026-06-01', 'sort_by' => 'revenue']);

        $this->assertSame('WP01', $result['products'][0]['sku']);
        $this->assertSame('revenue', $result['sort_by']);
    }

    /**
     * Builds an order item collection factory whose collection iterates the given rows.
     *
     * @param DataObject[] $items
     */
    private function factoryMock(array $items): OrderItemCollectionFactory
    {
        $collection = $this->createMock(OrderItemCollection::class);
        $collection->method('getIterator')->willReturn(new \ArrayIterator($items));

        $factory = $this->createMock(OrderItemCollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        return $factory;
    }
}
