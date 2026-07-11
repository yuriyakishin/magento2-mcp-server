<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Tools\Sales;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\Item\CollectionFactory as OrderItemCollectionFactory;
use Yu\McpServer\Model\ToolInterface;

/**
 * Aggregated sales report for a period: order count, revenue, average order value, a
 * status breakdown and the best-selling products. Aggregation happens in PHP over
 * collection rows (no raw SQL per module rules), bounded by the scan limits below —
 * a period exceeding them returns truncated, flagged results.
 */
class GetSalesSummary implements ToolInterface
{
    private const DEFAULT_TOP_PRODUCTS = 5;
    private const MAX_TOP_PRODUCTS = 20;

    /**
     * Upper bounds on rows scanned per call. When a period matches more orders than this,
     * the response carries "truncated": true and the numbers cover only the scanned rows.
     */
    private const ORDER_SCAN_LIMIT = 5000;
    private const ITEM_SCAN_LIMIT = 20000;

    public function __construct(
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly OrderItemCollectionFactory $orderItemCollectionFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'sales_summary';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Aggregated sales report for a date range: number of orders, revenue, average '
            . 'order value (per currency), a breakdown by order status and the best-selling '
            . 'products by quantity. Canceled orders are counted in the status breakdown but '
            . 'excluded from revenue and average order value; bestseller quantities are as '
            . 'ordered (cancellations not subtracted). Dates are compared in UTC. Typical '
            . 'use: "how were sales last week?", "compare June to May" (two calls).';
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
                'top_products' => [
                    'type' => 'integer',
                    'description' => 'How many best-selling products to include '
                        . '(default 5, max 20, 0 to skip).',
                ],
            ],
            'required' => ['created_from'],
        ];
    }

    /**
     * Aggregated revenue is order data — same admin permission as the other order tools.
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
        $createdFrom = $this->dateArgument($arguments, 'created_from');
        if ($createdFrom === null) {
            throw new \InvalidArgumentException('Argument "created_from" is required.');
        }
        $createdTo = $this->dateArgument($arguments, 'created_to');
        $topProducts = $this->topProductsArgument($arguments);

        // A date-only upper bound means "the whole day included".
        if ($createdTo !== null && strlen($createdTo) === 10) {
            $createdTo .= ' 23:59:59';
        }

        $summary = $this->summarizeOrders($createdFrom, $createdTo);
        $result = [
            'period' => ['from' => $createdFrom, 'to' => $createdTo],
            'orders_total' => $summary['orders_total'],
            'by_currency' => $summary['by_currency'],
            'by_status' => $summary['by_status'],
        ];
        if ($summary['truncated']) {
            $result['truncated'] = true;
        }
        if ($topProducts > 0) {
            $result['top_products'] = $this->bestsellers($createdFrom, $createdTo, $topProducts);
        }

        return $result;
    }

    /**
     * Scans the period's orders once, aggregating counts, per-currency revenue/AOV and the
     * status breakdown. Canceled orders stay in the counts but out of the revenue.
     *
     * @return array{orders_total: int, by_currency: array<int, array<string, mixed>>,
     *     by_status: array<string, int>, truncated: bool}
     */
    private function summarizeOrders(string $createdFrom, ?string $createdTo): array
    {
        $collection = $this->orderCollectionFactory->create();
        $collection->addFieldToFilter('created_at', ['gteq' => $createdFrom]);
        if ($createdTo !== null) {
            $collection->addFieldToFilter('created_at', ['lteq' => $createdTo]);
        }
        $collection->setPageSize(self::ORDER_SCAN_LIMIT);
        $collection->setCurPage(1);

        $ordersTotal = 0;
        $byStatus = [];
        $byCurrency = [];
        foreach ($collection as $order) {
            $ordersTotal++;

            $status = (string) ($order->getData('status') ?: 'unknown');
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;

            if ((string) $order->getData('state') === Order::STATE_CANCELED) {
                continue;
            }
            $currency = (string) ($order->getData('order_currency_code') ?: 'unknown');
            $byCurrency[$currency] ??= ['orders' => 0, 'revenue' => 0.0];
            $byCurrency[$currency]['orders']++;
            $byCurrency[$currency]['revenue'] += (float) $order->getData('grand_total');
        }

        $currencyRows = [];
        foreach ($byCurrency as $currency => $data) {
            $currencyRows[] = [
                'currency' => $currency,
                'orders' => $data['orders'],
                'revenue' => round($data['revenue'], 2),
                'avg_order_value' => round($data['revenue'] / $data['orders'], 2),
            ];
        }
        arsort($byStatus);

        return [
            'orders_total' => $ordersTotal,
            'by_currency' => $currencyRows,
            'by_status' => $byStatus,
            'truncated' => $ordersTotal === self::ORDER_SCAN_LIMIT,
        ];
    }

    /**
     * Aggregates the period's order items into a top-N list by quantity ordered. Only
     * top-level items are counted (children of configurable/bundle rows would double the
     * quantities).
     *
     * @return array<int, array{sku: string, name: string, qty: float, revenue: float}>
     */
    private function bestsellers(string $createdFrom, ?string $createdTo, int $limit): array
    {
        $collection = $this->orderItemCollectionFactory->create();
        $collection->addFieldToFilter('created_at', ['gteq' => $createdFrom]);
        if ($createdTo !== null) {
            $collection->addFieldToFilter('created_at', ['lteq' => $createdTo]);
        }
        $collection->addFieldToFilter('parent_item_id', ['null' => true]);
        $collection->setPageSize(self::ITEM_SCAN_LIMIT);
        $collection->setCurPage(1);

        $products = [];
        foreach ($collection as $item) {
            $sku = (string) $item->getData('sku');
            if ($sku === '') {
                continue;
            }
            $products[$sku] ??= [
                'sku' => $sku,
                'name' => (string) $item->getData('name'),
                'qty' => 0.0,
                'revenue' => 0.0,
            ];
            $products[$sku]['qty'] += (float) $item->getData('qty_ordered');
            $products[$sku]['revenue'] += (float) $item->getData('row_total');
        }

        usort($products, static fn (array $a, array $b) => $b['qty'] <=> $a['qty']);
        $top = array_slice(array_values($products), 0, $limit);

        return array_map(
            static function (array $row): array {
                $row['qty'] = round($row['qty'], 2);
                $row['revenue'] = round($row['revenue'], 2);
                return $row;
            },
            $top
        );
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
     * Validates the optional "top_products" argument (0 disables the bestseller list).
     */
    private function topProductsArgument(array $arguments): int
    {
        if (!isset($arguments['top_products'])) {
            return self::DEFAULT_TOP_PRODUCTS;
        }
        if (!is_numeric($arguments['top_products']) || (int) $arguments['top_products'] < 0) {
            throw new \InvalidArgumentException('Argument "top_products" must be an integer >= 0.');
        }

        return min((int) $arguments['top_products'], self::MAX_TOP_PRODUCTS);
    }
}
