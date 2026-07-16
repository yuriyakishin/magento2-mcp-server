<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Tools\Order;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Api\OrderRepositoryInterface;
use Yu\McpServer\Model\ToolInterface;

class GetCustomerOrders implements ToolInterface
{
    private const DEFAULT_LIMIT = 10;
    private const MAX_LIMIT = 50;

    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'order_list_by_customer';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Returns orders placed by an exact customer email address.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'customer_email' => [
                    'type' => 'string',
                    'description' => 'Exact customer email address to look up orders for.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of orders to return (default 10, max 50).',
                ],
            ],
            'required' => ['customer_email'],
        ];
    }

    /**
     * Exposes order data, so it requires the Sales ACL resource.
     */
    public function getRequiredAclResource(): ?string
    {
        return 'Magento_Sales::sales';
    }

    /**
     * @param array $arguments Expects `customer_email` (string, required) and optional `limit` (int).
     * @return array{orders: array<int, array{order_number: string, status: string, grand_total: mixed, created_at: string}>}
     */
    public function execute(array $arguments): array
    {
        $email = $arguments['customer_email'] ?? null;
        if (!is_string($email) || trim($email) === '') {
            throw new \InvalidArgumentException(
                'Argument "customer_email" is required and must be a non-empty string.'
            );
        }

        $limit = (int)($arguments['limit'] ?? self::DEFAULT_LIMIT);
        $limit = max(1, min($limit, self::MAX_LIMIT));

        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('customer_email', $email)
            ->setPageSize($limit)
            ->create();

        $orders = $this->orderRepository->getList($searchCriteria)->getItems();

        if ($orders === []) {
            throw new \RuntimeException(sprintf('No orders found for customer email "%s".', $email));
        }

        $result = [];
        foreach ($orders as $order) {
            $result[] = [
                'order_number' => $order->getIncrementId(),
                'status' => $order->getStatus(),
                'grand_total' => $order->getGrandTotal(),
                'created_at' => $order->getCreatedAt(),
            ];
        }

        return ['orders' => $result];
    }
}
