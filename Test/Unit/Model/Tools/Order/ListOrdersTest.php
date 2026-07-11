<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Tools\Order;

use Magento\Framework\Api\Search\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\Tools\Order\ListOrders;

class ListOrdersTest extends TestCase
{
    /**
     * Cross-customer order data must require the Sales ACL resource.
     */
    public function testRequiresSalesAclResource(): void
    {
        $tool = new ListOrders(
            $this->createMock(OrderRepositoryInterface::class),
            $this->createMock(SearchCriteriaBuilder::class),
            $this->createMock(SortOrderBuilder::class)
        );

        $this->assertSame('Magento_Sales::sales', $tool->getRequiredAclResource());
        $this->assertSame('order_list', $tool->getName());
    }

    /**
     * Malformed filter arguments must fail validation before any repository call.
     */
    public function testThrowsOnInvalidArguments(): void
    {
        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $orderRepository->expects($this->never())->method('getList');

        $tool = new ListOrders(
            $orderRepository,
            $this->createMock(SearchCriteriaBuilder::class),
            $this->createMock(SortOrderBuilder::class)
        );

        $invalid = [
            ['status' => ''],
            ['created_from' => '08.07.2026'],
            ['created_to' => '2026-13-45'],
            ['limit' => 0],
            ['page' => -1],
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
     * Filters land in the search criteria, orders come back newest first with the
     * documented fields, and "total" reflects the unpaginated match count.
     */
    public function testListsOrdersWithFilters(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getIncrementId')->willReturn('000000123');
        $order->method('getCreatedAt')->willReturn('2026-07-08 09:15:00');
        $order->method('getStatus')->willReturn('processing');
        $order->method('getGrandTotal')->willReturn(2499.00);
        $order->method('getOrderCurrencyCode')->willReturn('EUR');
        $order->method('getCustomerFirstname')->willReturn('Yuriy');
        $order->method('getCustomerLastname')->willReturn('A.');
        $order->method('getCustomerEmail')->willReturn('customer@example.com');
        $order->method('getTotalQtyOrdered')->willReturn(3.0);

        $searchResult = $this->createMock(OrderSearchResultInterface::class);
        $searchResult->method('getItems')->willReturn([$order]);
        $searchResult->method('getTotalCount')->willReturn(41);

        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $orderRepository->expects($this->once())->method('getList')->willReturn($searchResult);

        $filters = [];
        $searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $searchCriteriaBuilder->method('addFilter')->willReturnCallback(
            function (string $field, $value, string $conditionType = 'eq') use (&$filters, $searchCriteriaBuilder) {
                $filters[] = [$field, $value, $conditionType];
                return $searchCriteriaBuilder;
            }
        );
        $searchCriteriaBuilder->method('addSortOrder')->willReturnSelf();
        $searchCriteriaBuilder->expects($this->once())->method('setPageSize')->with(10)->willReturnSelf();
        $searchCriteriaBuilder->expects($this->once())->method('setCurrentPage')->with(2)->willReturnSelf();
        $searchCriteriaBuilder->method('create')->willReturn($this->createMock(SearchCriteria::class));

        $sortOrderBuilder = $this->createMock(SortOrderBuilder::class);
        $sortOrderBuilder->expects($this->once())->method('setField')->with('created_at')->willReturnSelf();
        $sortOrderBuilder->expects($this->once())->method('setDescendingDirection')->willReturnSelf();
        $sortOrderBuilder->method('create')->willReturn($this->createMock(SortOrder::class));

        $tool = new ListOrders($orderRepository, $searchCriteriaBuilder, $sortOrderBuilder);

        $result = $tool->execute([
            'status' => 'processing',
            'created_from' => '2026-07-01',
            'created_to' => '2026-07-08',
            'limit' => 10,
            'page' => 2,
        ]);

        $this->assertContains(['status', 'processing', 'eq'], $filters);
        $this->assertContains(['created_at', '2026-07-01', 'gteq'], $filters);
        $this->assertContains(['created_at', '2026-07-08 23:59:59', 'lteq'], $filters);
        $this->assertSame(1, $result['count']);
        $this->assertSame(41, $result['total']);
        $this->assertSame(2, $result['page']);
        $this->assertSame('000000123', $result['orders'][0]['order_number']);
        $this->assertSame('Yuriy A.', $result['orders'][0]['customer_name']);
        $this->assertSame(2499.00, $result['orders'][0]['grand_total']);
    }

    /**
     * With no filters at all the tool must still list orders with the default page size.
     */
    public function testListsOrdersWithoutFilters(): void
    {
        $searchResult = $this->createMock(OrderSearchResultInterface::class);
        $searchResult->method('getItems')->willReturn([]);
        $searchResult->method('getTotalCount')->willReturn(0);

        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $orderRepository->method('getList')->willReturn($searchResult);

        $searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $searchCriteriaBuilder->expects($this->never())->method('addFilter');
        $searchCriteriaBuilder->method('addSortOrder')->willReturnSelf();
        $searchCriteriaBuilder->expects($this->once())->method('setPageSize')->with(20)->willReturnSelf();
        $searchCriteriaBuilder->method('setCurrentPage')->willReturnSelf();
        $searchCriteriaBuilder->method('create')->willReturn($this->createMock(SearchCriteria::class));

        $sortOrderBuilder = $this->createMock(SortOrderBuilder::class);
        $sortOrderBuilder->method('setField')->willReturnSelf();
        $sortOrderBuilder->method('setDescendingDirection')->willReturnSelf();
        $sortOrderBuilder->method('create')->willReturn($this->createMock(SortOrder::class));

        $tool = new ListOrders($orderRepository, $searchCriteriaBuilder, $sortOrderBuilder);

        $result = $tool->execute([]);

        $this->assertSame(0, $result['count']);
        $this->assertSame([], $result['orders']);
    }
}
