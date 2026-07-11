<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Tools\Sales;

use Magento\Framework\DataObject;
use Magento\Sales\Model\ResourceModel\Order\Collection as OrderCollection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\Payment\Collection as PaymentCollection;
use Magento\Sales\Model\ResourceModel\Order\Payment\CollectionFactory as PaymentCollectionFactory;
use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\Tools\Sales\GetPaymentMethodStats;

class GetPaymentMethodStatsTest extends TestCase
{
    /**
     * Payment usage is order data — the tool must require the Sales ACL resource.
     */
    public function testRequiresSalesAclResource(): void
    {
        $tool = new GetPaymentMethodStats(
            $this->createMock(OrderCollectionFactory::class),
            $this->createMock(PaymentCollectionFactory::class)
        );

        $this->assertSame('Magento_Sales::sales', $tool->getRequiredAclResource());
        $this->assertSame('sales_payment_stats', $tool->getName());
    }

    /**
     * created_from is mandatory; malformed dates must fail validation before any
     * collection is built.
     */
    public function testThrowsOnInvalidArguments(): void
    {
        $orderFactory = $this->createMock(OrderCollectionFactory::class);
        $orderFactory->expects($this->never())->method('create');

        $tool = new GetPaymentMethodStats($orderFactory, $this->createMock(PaymentCollectionFactory::class));

        foreach ([[], ['created_from' => 'June']] as $arguments) {
            try {
                $tool->execute($arguments);
                $this->fail('Expected InvalidArgumentException for: ' . json_encode($arguments));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Payments group per method+currency with the human title from
     * additional_information; canceled orders are excluded entirely.
     */
    public function testAggregatesByPaymentMethod(): void
    {
        $orders = [
            new DataObject([
                'entity_id' => '1',
                'state' => 'complete',
                'order_currency_code' => 'EUR',
                'grand_total' => '1000.00',
            ]),
            new DataObject([
                'entity_id' => '2',
                'state' => 'processing',
                'order_currency_code' => 'EUR',
                'grand_total' => '500.00',
            ]),
            new DataObject([
                'entity_id' => '3',
                'state' => 'canceled',
                'order_currency_code' => 'EUR',
                'grand_total' => '300.00',
            ]),
        ];
        $payments = [
            new DataObject([
                'parent_id' => '1',
                'method' => 'checkmo',
                'additional_information' => '{"method_title":"Check / Money order"}',
            ]),
            new DataObject(['parent_id' => '2', 'method' => 'checkmo', 'additional_information' => null]),
            new DataObject(['parent_id' => '3', 'method' => 'banktransfer', 'additional_information' => null]),
        ];

        $tool = new GetPaymentMethodStats(
            $this->orderFactoryMock($orders),
            $this->paymentFactoryMock($payments)
        );

        $result = $tool->execute(['created_from' => '2026-06-01']);

        $this->assertCount(1, $result['methods']);
        $this->assertSame('checkmo', $result['methods'][0]['method']);
        $this->assertSame('Check / Money order', $result['methods'][0]['title']);
        $this->assertSame(2, $result['methods'][0]['orders']);
        $this->assertSame(1500.0, $result['methods'][0]['revenue']);
    }

    /**
     * No orders in the period means no payment query and an empty method list.
     */
    public function testEmptyPeriodSkipsPaymentQuery(): void
    {
        $paymentFactory = $this->createMock(PaymentCollectionFactory::class);
        $paymentFactory->expects($this->never())->method('create');

        $tool = new GetPaymentMethodStats($this->orderFactoryMock([]), $paymentFactory);

        $result = $tool->execute(['created_from' => '2026-06-01']);

        $this->assertSame([], $result['methods']);
    }

    /**
     * Builds an order collection factory whose collection iterates the given rows.
     *
     * @param DataObject[] $orders
     */
    private function orderFactoryMock(array $orders): OrderCollectionFactory
    {
        $collection = $this->createMock(OrderCollection::class);
        $collection->method('getIterator')->willReturn(new \ArrayIterator($orders));

        $factory = $this->createMock(OrderCollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        return $factory;
    }

    /**
     * Builds a payment collection factory whose collection iterates the given rows.
     *
     * @param DataObject[] $payments
     */
    private function paymentFactoryMock(array $payments): PaymentCollectionFactory
    {
        $collection = $this->createMock(PaymentCollection::class);
        $collection->method('getIterator')->willReturn(new \ArrayIterator($payments));

        $factory = $this->createMock(PaymentCollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        return $factory;
    }
}
