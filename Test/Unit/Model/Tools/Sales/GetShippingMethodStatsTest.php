<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Tools\Sales;

use Magento\Framework\DataObject;
use Magento\Sales\Model\ResourceModel\Order\Collection as OrderCollection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\Tools\Sales\GetShippingMethodStats;

class GetShippingMethodStatsTest extends TestCase
{
    /**
     * Shipping usage is order data — the tool must require the Sales ACL resource.
     */
    public function testRequiresSalesAclResource(): void
    {
        $tool = new GetShippingMethodStats($this->createMock(OrderCollectionFactory::class));

        $this->assertSame('Magento_Sales::sales', $tool->getRequiredAclResource());
        $this->assertSame('sales_shipping_stats', $tool->getName());
    }

    /**
     * created_from is mandatory; malformed dates must fail validation before any
     * collection is built.
     */
    public function testThrowsOnInvalidArguments(): void
    {
        $factory = $this->createMock(OrderCollectionFactory::class);
        $factory->expects($this->never())->method('create');

        $tool = new GetShippingMethodStats($factory);

        foreach ([[], ['created_from' => '01/06/2026']] as $arguments) {
            try {
                $tool->execute($arguments);
                $this->fail('Expected InvalidArgumentException for: ' . json_encode($arguments));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Orders group per shipping method+currency with shipping totals; canceled orders
     * are excluded; virtual orders land in "(no shipping)".
     */
    public function testAggregatesByShippingMethod(): void
    {
        $orders = [
            new DataObject([
                'state' => 'complete',
                'shipping_method' => 'flatrate_flatrate',
                'shipping_description' => 'Flat Rate - Fixed',
                'order_currency_code' => 'EUR',
                'grand_total' => '1000.00',
                'shipping_amount' => '50.00',
            ]),
            new DataObject([
                'state' => 'processing',
                'shipping_method' => 'flatrate_flatrate',
                'shipping_description' => 'Flat Rate - Fixed',
                'order_currency_code' => 'EUR',
                'grand_total' => '500.00',
                'shipping_amount' => '50.00',
            ]),
            new DataObject([
                'state' => 'complete',
                'shipping_method' => null,
                'shipping_description' => null,
                'order_currency_code' => 'EUR',
                'grand_total' => '200.00',
                'shipping_amount' => '0.00',
            ]),
            new DataObject([
                'state' => 'canceled',
                'shipping_method' => 'flatrate_flatrate',
                'shipping_description' => 'Flat Rate - Fixed',
                'order_currency_code' => 'EUR',
                'grand_total' => '300.00',
                'shipping_amount' => '50.00',
            ]),
        ];

        $result = (new GetShippingMethodStats($this->factoryMock($orders)))
            ->execute(['created_from' => '2026-06-01']);

        $this->assertCount(2, $result['methods']);
        $this->assertSame('flatrate_flatrate', $result['methods'][0]['method']);
        $this->assertSame(2, $result['methods'][0]['orders']);
        $this->assertSame(1500.0, $result['methods'][0]['revenue']);
        $this->assertSame(100.0, $result['methods'][0]['shipping_total']);
        $this->assertSame('(no shipping)', $result['methods'][1]['method']);
    }

    /**
     * Builds an order collection factory whose collection iterates the given rows.
     *
     * @param DataObject[] $orders
     */
    private function factoryMock(array $orders): OrderCollectionFactory
    {
        $collection = $this->createMock(OrderCollection::class);
        $collection->method('getIterator')->willReturn(new \ArrayIterator($orders));

        $factory = $this->createMock(OrderCollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        return $factory;
    }
}
