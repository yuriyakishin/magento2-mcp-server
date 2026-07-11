<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Tools\Order;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Sales\Api\OrderRepositoryInterface;
use Yu\McpServer\Model\ToolInterface;

/**
 * Broad order listing: orders across all customers, newest first, with status and
 * date-range filters. Complements order_get (one exact number) and
 * order_list_by_customer (one exact email).
 */
class ListOrders implements ToolInterface
{
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 50;

    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly SortOrderBuilder $sortOrderBuilder
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'order_list';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Lists orders across all customers, newest first, with optional status and '
            . 'date-range filters. Returns order number, date, status, grand total, currency, '
            . 'customer name/email and item quantity. Typical use: "orders placed today", '
            . '"orders stuck in processing". Dates are compared in UTC. Use order_get '
            . 'for one order\'s full details.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'description' => 'Order status code to filter by, e.g. "pending", '
                        . '"processing", "complete", "canceled". Omit for all statuses.',
                ],
                'created_from' => [
                    'type' => 'string',
                    'description' => 'Only orders placed at or after this UTC date, '
                        . '"YYYY-MM-DD" or "YYYY-MM-DD HH:MM:SS".',
                ],
                'created_to' => [
                    'type' => 'string',
                    'description' => 'Only orders placed at or before this UTC date, '
                        . '"YYYY-MM-DD" (whole day included) or "YYYY-MM-DD HH:MM:SS".',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Orders per page (default 20, max 50).',
                ],
                'page' => [
                    'type' => 'integer',
                    'description' => 'Page number, starting at 1 (default 1).',
                ],
            ],
            'required' => [],
        ];
    }

    /**
     * Order data across all customers — same admin permission as the other order tools.
     */
    public function getRequiredAclResource(): ?string
    {
        return 'Magento_Sales::sales';
    }

    /**
     * @param array $arguments See getInputSchema().
     * @return array{orders: array<int, array<string, mixed>>, count: int, total: int, page: int}
     */
    public function execute(array $arguments): array
    {
        $status = $this->statusArgument($arguments);
        $createdFrom = $this->dateArgument($arguments, 'created_from');
        $createdTo = $this->dateArgument($arguments, 'created_to');
        $limit = $this->positiveIntArgument($arguments, 'limit', self::DEFAULT_LIMIT, self::MAX_LIMIT);
        $page = $this->positiveIntArgument($arguments, 'page', 1, PHP_INT_MAX);

        // A date-only upper bound means "the whole day included".
        if ($createdTo !== null && strlen($createdTo) === 10) {
            $createdTo .= ' 23:59:59';
        }

        if ($status !== null) {
            $this->searchCriteriaBuilder->addFilter('status', $status);
        }
        if ($createdFrom !== null) {
            $this->searchCriteriaBuilder->addFilter('created_at', $createdFrom, 'gteq');
        }
        if ($createdTo !== null) {
            $this->searchCriteriaBuilder->addFilter('created_at', $createdTo, 'lteq');
        }

        $sortOrder = $this->sortOrderBuilder
            ->setField('created_at')
            ->setDescendingDirection()
            ->create();

        $searchCriteria = $this->searchCriteriaBuilder
            ->addSortOrder($sortOrder)
            ->setPageSize($limit)
            ->setCurrentPage($page)
            ->create();

        $searchResult = $this->orderRepository->getList($searchCriteria);

        $orders = [];
        foreach ($searchResult->getItems() as $order) {
            $orders[] = [
                'order_number' => $order->getIncrementId(),
                'created_at' => $order->getCreatedAt(),
                'status' => $order->getStatus(),
                'grand_total' => (float) $order->getGrandTotal(),
                'currency' => $order->getOrderCurrencyCode(),
                'customer_name' => trim(
                    ($order->getCustomerFirstname() ?? '') . ' ' . ($order->getCustomerLastname() ?? '')
                ) ?: null,
                'customer_email' => $order->getCustomerEmail(),
                'items_qty' => (float) $order->getTotalQtyOrdered(),
            ];
        }

        return [
            'orders' => $orders,
            'count' => count($orders),
            'total' => (int) $searchResult->getTotalCount(),
            'page' => $page,
        ];
    }

    /**
     * Validates the optional "status" argument.
     */
    private function statusArgument(array $arguments): ?string
    {
        $status = $arguments['status'] ?? null;
        if ($status === null) {
            return null;
        }
        if (!is_string($status) || trim($status) === '') {
            throw new \InvalidArgumentException('Argument "status" must be a non-empty string.');
        }

        return trim($status);
    }

    /**
     * Validates an optional date argument: "YYYY-MM-DD" or "YYYY-MM-DD HH:MM:SS".
     */
    private function dateArgument(array $arguments, string $key): ?string
    {
        $value = $arguments[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)
            || !preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $value)
            || strtotime($value) === false
        ) {
            throw new \InvalidArgumentException(
                sprintf('Argument "%s" must be a valid "YYYY-MM-DD" or "YYYY-MM-DD HH:MM:SS" date.', $key)
            );
        }

        return $value;
    }

    /**
     * Validates an optional positive-integer argument, applying a default and a cap.
     */
    private function positiveIntArgument(array $arguments, string $key, int $default, int $max): int
    {
        if (!isset($arguments[$key])) {
            return $default;
        }
        if (!is_numeric($arguments[$key]) || (int) $arguments[$key] < 1) {
            throw new \InvalidArgumentException(sprintf('Argument "%s" must be a positive integer.', $key));
        }

        return min((int) $arguments[$key], $max);
    }
}
