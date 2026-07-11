<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Tools\Order;

use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\Tools\Order\GetCustomerOrders;

class GetCustomerOrdersTest extends TestCase
{
    /**
     * order_list_by_customer exposes order data, so it must require the Sales ACL resource.
     */
    public function testRequiresSalesAclResource(): void
    {
        $tool = new GetCustomerOrders(
            $this->createMock(OrderRepositoryInterface::class),
            $this->createMock(SearchCriteriaBuilder::class)
        );

        $this->assertSame('Magento_Sales::sales', $tool->getRequiredAclResource());
    }

    /**
     * A matching customer email should return that customer's orders.
     */
    public function testReturnsOrdersForExistingCustomerEmail(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getIncrementId')->willReturn('000000123');
        $order->method('getStatus')->willReturn('complete');
        $order->method('getGrandTotal')->willReturn(50.0);
        $order->method('getCreatedAt')->willReturn('2026-01-01 00:00:00');

        $searchResults = $this->createMock(SearchResultsInterface::class);
        $searchResults->method('getItems')->willReturn([$order]);

        $searchCriteria = $this->createMock(SearchCriteria::class);

        $searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $searchCriteriaBuilder->method('setPageSize')->willReturnSelf();
        $searchCriteriaBuilder->method('create')->willReturn($searchCriteria);

        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $orderRepository->method('getList')->with($searchCriteria)->willReturn($searchResults);

        $tool = new GetCustomerOrders($orderRepository, $searchCriteriaBuilder);

        $result = $tool->execute(['customer_email' => 'customer@example.com']);

        $this->assertCount(1, $result['orders']);
        $this->assertSame('000000123', $result['orders'][0]['order_number']);
    }

    /**
     * A missing required "customer_email" argument must fail validation.
     */
    public function testThrowsWhenCustomerEmailArgumentIsMissing(): void
    {
        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);

        $tool = new GetCustomerOrders($orderRepository, $searchCriteriaBuilder);

        $this->expectException(\InvalidArgumentException::class);

        $tool->execute([]);
    }

    /**
     * A customer email with no matching orders must fail with a "not found" runtime exception.
     */
    public function testThrowsWhenNoOrdersFound(): void
    {
        $searchResults = $this->createMock(SearchResultsInterface::class);
        $searchResults->method('getItems')->willReturn([]);

        $searchCriteria = $this->createMock(SearchCriteria::class);

        $searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $searchCriteriaBuilder->method('setPageSize')->willReturnSelf();
        $searchCriteriaBuilder->method('create')->willReturn($searchCriteria);

        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $orderRepository->method('getList')->willReturn($searchResults);

        $tool = new GetCustomerOrders($orderRepository, $searchCriteriaBuilder);

        $this->expectException(\RuntimeException::class);

        $tool->execute(['customer_email' => 'nobody@example.com']);
    }
}
