<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Tools\Customer;

use Magento\Customer\Model\ResourceModel\Customer\Collection as CustomerCollection;
use Magento\Customer\Model\ResourceModel\Customer\CollectionFactory as CustomerCollectionFactory;
use Magento\Framework\DataObject;
use Magento\Sales\Model\ResourceModel\Order\Collection as OrderCollection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\Tools\Customer\SearchCustomers;

class SearchCustomersTest extends TestCase
{
    /**
     * Customer personal data — the tool must require the All Customers grid ACL resource.
     */
    public function testRequiresCustomerManageAclResource(): void
    {
        $tool = new SearchCustomers(
            $this->createMock(CustomerCollectionFactory::class),
            $this->createMock(OrderCollectionFactory::class)
        );

        $this->assertSame('Magento_Customer::manage', $tool->getRequiredAclResource());
        $this->assertSame('customer_search', $tool->getName());
    }

    /**
     * A missing or empty "query" argument must fail validation.
     */
    public function testThrowsOnMissingQuery(): void
    {
        $customerCollectionFactory = $this->createMock(CustomerCollectionFactory::class);
        $customerCollectionFactory->expects($this->never())->method('create');

        $tool = new SearchCustomers(
            $customerCollectionFactory,
            $this->createMock(OrderCollectionFactory::class)
        );

        foreach ([[], ['query' => ''], ['query' => '   ']] as $arguments) {
            try {
                $tool->execute($arguments);
                $this->fail('Expected InvalidArgumentException for: ' . json_encode($arguments));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Matches come back with order count and lifetime spend aggregated per customer;
     * canceled orders count toward neither number.
     */
    public function testReturnsCustomersWithOrderTotals(): void
    {
        $customer = new DataObject([
            'id' => 42,
            'firstname' => 'Emma',
            'lastname' => 'K.',
            'email' => 'emma@example.com',
            'created_at' => '2025-03-01 10:00:00',
        ]);

        $orders = [
            new DataObject(['customer_id' => '42', 'state' => 'complete', 'base_grand_total' => '1000.00']),
            new DataObject(['customer_id' => '42', 'state' => 'processing', 'base_grand_total' => '500.50']),
            new DataObject(['customer_id' => '42', 'state' => 'canceled', 'base_grand_total' => '300.00']),
        ];

        $tool = new SearchCustomers(
            $this->customerCollectionFactoryMock([$customer]),
            $this->orderCollectionFactoryMock($orders)
        );

        $result = $tool->execute(['query' => 'emma']);

        $this->assertSame(1, $result['count']);
        $row = $result['customers'][0];
        $this->assertSame(42, $row['customer_id']);
        $this->assertSame('Emma K.', $row['name']);
        $this->assertSame('emma@example.com', $row['email']);
        $this->assertSame(2, $row['orders_count']);
        $this->assertSame(1500.5, $row['total_spent_base']);
    }

    /**
     * No matches must yield an empty list without querying orders at all.
     */
    public function testEmptyResultSkipsOrderQuery(): void
    {
        $orderCollectionFactory = $this->createMock(OrderCollectionFactory::class);
        $orderCollectionFactory->expects($this->never())->method('create');

        $tool = new SearchCustomers(
            $this->customerCollectionFactoryMock([]),
            $orderCollectionFactory
        );

        $result = $tool->execute(['query' => 'nobody']);

        $this->assertSame(0, $result['count']);
        $this->assertSame([], $result['customers']);
    }

    /**
     * Builds a customer collection factory whose collection iterates the given rows.
     *
     * @param DataObject[] $customers
     */
    private function customerCollectionFactoryMock(array $customers): CustomerCollectionFactory
    {
        $collection = $this->createMock(CustomerCollection::class);
        $collection->method('addAttributeToSelect')->willReturnSelf();
        $collection->method('addAttributeToFilter')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($customers));

        $factory = $this->createMock(CustomerCollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        return $factory;
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
}
