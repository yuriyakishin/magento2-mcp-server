<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Tools\Sales;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Yu\McpServer\Model\ToolInterface;

/**
 * How orders ship: orders, revenue and shipping charges per shipping method for a
 * period. Single pass over the order collection — the shipping method lives directly on
 * the order row.
 */
class GetShippingMethodStats implements ToolInterface
{
    private const ORDER_SCAN_LIMIT = 5000;

    public function __construct(
        private readonly OrderCollectionFactory $orderCollectionFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'sales_shipping_stats';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Orders, revenue and shipping charges per SHIPPING method for a date range '
            . '(per currency): which delivery options customers choose and what they pay for '
            . 'them. Orders without shipping (virtual/downloadable) are grouped under '
            . '"(no shipping)". Canceled orders are excluded. Dates are compared in UTC. '
            . 'Typical use: "which delivery methods do customers pick?".';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'created_from' => [
                    'type' => 'string',
                    'description' => 'Start of the period (inclusive), UTC, "YYYY-MM-DD" or '
                        . '"YYYY-MM-DD HH:MM:SS".',
                ],
                'created_to' => [
                    'type' => 'string',
                    'description' => 'End of the period (inclusive), UTC, "YYYY-MM-DD" '
                        . '(whole day included) or "YYYY-MM-DD HH:MM:SS". Omit for "up to now".',
                ],
            ],
            'required' => ['created_from'],
        ];
    }

    /**
     * Shipping usage is order data — same admin permission as the other order tools.
     */
    public function getRequiredAclResource(): ?string
    {
        return 'Magento_Sales::sales';
    }

    /**
     * @param array $arguments See getInputSchema().
     * @return array<string, mixed>
     */
    public function execute(array $arguments): array
    {
        $createdFrom = DateRange::requiredDate($arguments, 'created_from');
        $createdTo = DateRange::optionalDate($arguments, 'created_to');

        $collection = $this->orderCollectionFactory->create();
        $collection->addFieldToFilter('created_at', ['gteq' => $createdFrom]);
        if ($createdTo !== null) {
            $collection->addFieldToFilter('created_at', ['lteq' => $createdTo]);
        }
        $collection->setPageSize(self::ORDER_SCAN_LIMIT);
        $collection->setCurPage(1);

        $methods = [];
        $rowsScanned = 0;
        foreach ($collection as $order) {
            $rowsScanned++;
            if ((string)$order->getData('state') === Order::STATE_CANCELED) {
                continue;
            }
            $method = (string)($order->getData('shipping_method') ?: '(no shipping)');
            $currency = (string)($order->getData('order_currency_code') ?: 'unknown');
            $key = $method . '|' . $currency;

            $methods[$key] ??= [
                'method' => $method,
                'description' => (string)($order->getData('shipping_description') ?: ''),
                'currency' => $currency,
                'orders' => 0,
                'revenue' => 0.0,
                'shipping_total' => 0.0,
            ];
            $methods[$key]['orders']++;
            $methods[$key]['revenue'] += (float)$order->getData('grand_total');
            $methods[$key]['shipping_total'] += (float)$order->getData('shipping_amount');
        }

        $rows = array_map(
            static function (array $row): array {
                $row['revenue'] = round($row['revenue'], 2);
                $row['shipping_total'] = round($row['shipping_total'], 2);
                return $row;
            },
            array_values($methods)
        );
        usort($rows, static fn (array $a, array $b) => $b['orders'] <=> $a['orders']);

        $result = [
            'period' => ['from' => $createdFrom, 'to' => $createdTo],
            'methods' => $rows,
        ];
        if ($rowsScanned === self::ORDER_SCAN_LIMIT) {
            $result['truncated'] = true;
        }

        return $result;
    }
}
