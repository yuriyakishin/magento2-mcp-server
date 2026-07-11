<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Tools\Customer;

use Magento\Customer\Model\ResourceModel\Customer\CollectionFactory as CustomerCollectionFactory;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Yu\McpServer\Model\ToolInterface;

/**
 * Customer lookup by name or email, with order count and lifetime spend per match.
 * Deliberately a search, not a profile dump: addresses, phone numbers and other profile
 * data stay out of the result.
 */
class SearchCustomers implements ToolInterface
{
    private const DEFAULT_LIMIT = 10;
    private const MAX_LIMIT = 50;

    public function __construct(
        private readonly CustomerCollectionFactory $customerCollectionFactory,
        private readonly OrderCollectionFactory $orderCollectionFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'customer_search';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Searches registered customers by name or email (partial match) and returns '
            . 'each match with registration date, number of orders and lifetime spend in '
            . 'the store\'s base currency (canceled orders excluded). Use order_list_by_customer '
            . 'with the exact email for the order list itself. Guest checkout buyers are not '
            . 'registered customers and will not appear here.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Search text matched against first name, last name and email.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of customers to return (default 10, max 50).',
                ],
            ],
            'required' => ['query'],
        ];
    }

    /**
     * Customer personal data — same admin permission as the All Customers grid.
     */
    public function getRequiredAclResource(): ?string
    {
        return 'Magento_Customer::manage';
    }

    /**
     * @param array $arguments Expects `query` (string, required) and optional `limit` (int).
     * @return array{customers: array<int, array<string, mixed>>, count: int}
     */
    public function execute(array $arguments): array
    {
        $query = $arguments['query'] ?? null;
        if (!is_string($query) || trim($query) === '') {
            throw new \InvalidArgumentException('Argument "query" is required and must be a non-empty string.');
        }
        $query = trim($query);

        $limit = (int) ($arguments['limit'] ?? self::DEFAULT_LIMIT);
        $limit = max(1, min($limit, self::MAX_LIMIT));

        $collection = $this->customerCollectionFactory->create();
        $collection->addAttributeToSelect(['firstname', 'lastname', 'email', 'created_at', 'group_id']);
        $collection->addAttributeToFilter([
            ['attribute' => 'firstname', 'like' => '%' . $query . '%'],
            ['attribute' => 'lastname', 'like' => '%' . $query . '%'],
            ['attribute' => 'email', 'like' => '%' . $query . '%'],
        ]);
        $collection->setPageSize($limit);

        $customers = [];
        $customerIds = [];
        foreach ($collection as $customer) {
            $customerId = (int) $customer->getId();
            $customerIds[] = $customerId;
            $customers[$customerId] = [
                'customer_id' => $customerId,
                'name' => trim(
                    ($customer->getData('firstname') ?? '') . ' ' . ($customer->getData('lastname') ?? '')
                ),
                'email' => $customer->getData('email'),
                'registered_at' => $customer->getData('created_at'),
                'orders_count' => 0,
                'total_spent_base' => 0.0,
            ];
        }

        foreach ($this->aggregateOrders($customerIds) as $customerId => $totals) {
            $customers[$customerId]['orders_count'] = $totals['orders'];
            $customers[$customerId]['total_spent_base'] = round($totals['spent'], 2);
        }

        return [
            'customers' => array_values($customers),
            'count' => count($customers),
        ];
    }

    /**
     * Aggregates order count and lifetime spend (base currency) for the page's customers
     * in one query. Canceled orders are excluded from both numbers.
     *
     * @param int[] $customerIds
     * @return array<int, array{orders: int, spent: float}>
     */
    private function aggregateOrders(array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        $collection = $this->orderCollectionFactory->create();
        $collection->addFieldToFilter('customer_id', ['in' => $customerIds]);

        $totals = [];
        foreach ($collection as $order) {
            if ((string) $order->getData('state') === Order::STATE_CANCELED) {
                continue;
            }
            $customerId = (int) $order->getData('customer_id');
            $totals[$customerId] ??= ['orders' => 0, 'spent' => 0.0];
            $totals[$customerId]['orders']++;
            $totals[$customerId]['spent'] += (float) $order->getData('base_grand_total');
        }

        return $totals;
    }
}
