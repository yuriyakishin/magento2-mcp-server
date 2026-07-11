<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Tools\Order;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Api\OrderRepositoryInterface;
use Yu\McpServer\Model\ToolInterface;

class GetOrderStatus implements ToolInterface
{
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
        return 'order_get';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Returns the status and basic details of an order by its exact order number (increment ID).';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'order_number' => [
                    'type' => 'string',
                    'description' => 'Order increment ID, e.g. "000000123".',
                ],
            ],
            'required' => ['order_number'],
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
     * @param array $arguments Expects `order_number` (string, required).
     * @return array{order_number: string, status: string, grand_total: mixed, created_at: string}
     */
    public function execute(array $arguments): array
    {
        $orderNumber = $arguments['order_number'] ?? null;
        if (!is_string($orderNumber) || trim($orderNumber) === '') {
            throw new \InvalidArgumentException(
                'Argument "order_number" is required and must be a non-empty string.'
            );
        }

        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('increment_id', $orderNumber)
            ->create();

        $orders = $this->orderRepository->getList($searchCriteria)->getItems();
        $order = reset($orders);

        if ($order === false) {
            throw new \RuntimeException(sprintf('Order "%s" was not found.', $orderNumber));
        }

        return [
            'order_number' => $order->getIncrementId(),
            'status' => $order->getStatus(),
            'grand_total' => $order->getGrandTotal(),
            'created_at' => $order->getCreatedAt(),
        ];
    }
}
