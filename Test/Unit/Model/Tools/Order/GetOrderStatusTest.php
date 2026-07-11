<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Tools\Order;

use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\Tools\Order\GetOrderStatus;

class GetOrderStatusTest extends TestCase
{
    /**
     * order_get exposes order data, so it must require the Sales ACL resource.
     */
    public function testRequiresSalesAclResource(): void
    {
        $tool = new GetOrderStatus(
            $this->createMock(OrderRepositoryInterface::class),
            $this->createMock(SearchCriteriaBuilder::class)
        );

        $this->assertSame('Magento_Sales::sales', $tool->getRequiredAclResource());
    }

    /**
     * A matching order should be returned with its status and totals.
     */
    public function testReturnsStatusForExistingOrder(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getIncrementId')->willReturn('000000123');
        $order->method('getStatus')->willReturn('processing');
        $order->method('getGrandTotal')->willReturn(99.5);
        $order->method('getCreatedAt')->willReturn('2026-01-01 00:00:00');

        $searchResults = $this->createMock(SearchResultsInterface::class);
        $searchResults->method('getItems')->willReturn([$order]);

        $searchCriteria = $this->createMock(SearchCriteria::class);

        $searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $searchCriteriaBuilder->method('create')->willReturn($searchCriteria);

        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $orderRepository->method('getList')->with($searchCriteria)->willReturn($searchResults);

        $tool = new GetOrderStatus($orderRepository, $searchCriteriaBuilder);

        $result = $tool->execute(['order_number' => '000000123']);

        $this->assertSame('000000123', $result['order_number']);
        $this->assertSame('processing', $result['status']);
    }

    /**
     * A missing required "order_number" argument must fail validation.
     */
    public function testThrowsWhenOrderNumberArgumentIsMissing(): void
    {
        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);

        $tool = new GetOrderStatus($orderRepository, $searchCriteriaBuilder);

        $this->expectException(\InvalidArgumentException::class);

        $tool->execute([]);
    }

    /**
     * A non-matching order number must fail with a "not found" runtime exception.
     */
    public function testThrowsWhenOrderIsNotFound(): void
    {
        $searchResults = $this->createMock(SearchResultsInterface::class);
        $searchResults->method('getItems')->willReturn([]);

        $searchCriteria = $this->createMock(SearchCriteria::class);

        $searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $searchCriteriaBuilder->method('create')->willReturn($searchCriteria);

        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $orderRepository->method('getList')->willReturn($searchResults);

        $tool = new GetOrderStatus($orderRepository, $searchCriteriaBuilder);

        $this->expectException(\RuntimeException::class);

        $tool->execute(['order_number' => 'does-not-exist']);
    }
}
