<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Tools\Sales;

use Magento\Framework\DataObject;
use Magento\Sales\Model\ResourceModel\Order\Collection as OrderCollection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\Item\Collection as OrderItemCollection;
use Magento\Sales\Model\ResourceModel\Order\Item\CollectionFactory as OrderItemCollectionFactory;
use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\Tools\Sales\GetSalesSummary;

class GetSalesSummaryTest extends TestCase
{
    /**
     * Aggregated revenue is order data — the tool must require the Sales ACL resource.
     */
    public function testRequiresSalesAclResource(): void
    {
        $tool = new GetSalesSummary(
            $this->createMock(OrderCollectionFactory::class),
            $this->createMock(OrderItemCollectionFactory::class)
        );

        $this->assertSame('Magento_Sales::sales', $tool->getRequiredAclResource());
        $this->assertSame('sales_summary', $tool->getName());
    }

    /**
     * created_from is mandatory and malformed arguments must fail validation before any
     * collection is built.
     */
    public function testThrowsOnInvalidArguments(): void
    {
        $orderCollectionFactory = $this->createMock(OrderCollectionFactory::class);
        $orderCollectionFactory->expects($this->never())->method('create');

        $tool = new GetSalesSummary(
            $orderCollectionFactory,
            $this->createMock(OrderItemCollectionFactory::class)
        );

        $invalid = [
            [],
            ['created_from' => '01.06.2026'],
            ['created_from' => '2026-06-01', 'created_to' => 'yesterday'],
            ['created_from' => '2026-06-01', 'top_products' => -1],
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
     * Canceled orders stay in the count and status breakdown but out of revenue/AOV;
     * bestsellers aggregate item rows per SKU sorted by quantity.
     */
    public function testAggregatesOrdersAndBestsellers(): void
    {
        $orders = [
            new DataObject([
                'status' => 'complete',
                'state' => 'complete',
                'order_currency_code' => 'EUR',
                'grand_total' => '1000.00',
            ]),
            new DataObject([
                'status' => 'processing',
                'state' => 'processing',
                'order_currency_code' => 'EUR',
                'grand_total' => '500.00',
            ]),
            new DataObject([
                'status' => 'canceled',
                'state' => 'canceled',
                'order_currency_code' => 'EUR',
                'grand_total' => '300.00',
            ]),
        ];

        $items = [
            new DataObject(['sku' => 'WS02', 'name' => 'Top', 'qty_ordered' => '2', 'row_total' => '400.00']),
            new DataObject(['sku' => 'WS02', 'name' => 'Top', 'qty_ordered' => '3', 'row_total' => '600.00']),
            new DataObject(['sku' => 'WP01', 'name' => 'Pant', 'qty_ordered' => '1', 'row_total' => '500.00']),
        ];

        $tool = new GetSalesSummary(
            $this->orderCollectionFactoryMock($orders),
            $this->itemCollectionFactoryMock($items)
        );

        $result = $tool->execute(['created_from' => '2026-06-01', 'created_to' => '2026-06-30']);

        $this->assertSame(3, $result['orders_total']);
        $this->assertSame(
            [['currency' => 'EUR', 'orders' => 2, 'revenue' => 1500.0, 'avg_order_value' => 750.0]],
            $result['by_currency']
        );
        $this->assertSame(1, $result['by_status']['canceled']);
        $this->assertArrayNotHasKey('truncated', $result);

        $this->assertSame('WS02', $result['top_products'][0]['sku']);
        $this->assertSame(5.0, $result['top_products'][0]['qty']);
        $this->assertSame(1000.0, $result['top_products'][0]['revenue']);
        $this->assertSame('WP01', $result['top_products'][1]['sku']);
    }

    /**
     * top_products = 0 must skip the item scan entirely.
     */
    public function testZeroTopProductsSkipsItemScan(): void
    {
        $itemCollectionFactory = $this->createMock(OrderItemCollectionFactory::class);
        $itemCollectionFactory->expects($this->never())->method('create');

        $tool = new GetSalesSummary($this->orderCollectionFactoryMock([]), $itemCollectionFactory);

        $result = $tool->execute(['created_from' => '2026-06-01', 'top_products' => 0]);

        $this->assertSame(0, $result['orders_total']);
        $this->assertArrayNotHasKey('top_products', $result);
    }

    /**
     * Builds an order collection factory whose collection iterates the given rows.
     *
     * @param DataObject[] $orders
     */
    private function orderCollectionFactoryMock(array $orders): OrderCollectionFactory
    {
        $collection = $this->createMock(OrderCollection::class);
        $collection->method('getIterator')->willReturn(new \ArrayIterator($orders));

        $factory = $this->createMock(OrderCollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        return $factory;
    }

    /**
     * Builds an order item collection factory whose collection iterates the given rows.
     *
     * @param DataObject[] $items
     */
    private function itemCollectionFactoryMock(array $items): OrderItemCollectionFactory
    {
        $collection = $this->createMock(OrderItemCollection::class);
        $collection->method('getIterator')->willReturn(new \ArrayIterator($items));

        $factory = $this->createMock(OrderItemCollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        return $factory;
    }
}
