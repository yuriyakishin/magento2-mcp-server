<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Tools\Sales;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Yu\McpServer\Model\ToolInterface;

/**
 * Compares two date ranges in one call: order counts, per-currency revenue and average
 * order value for each period, plus percentage deltas. Saves the client from making two
 * sales_summary calls and computing the changes itself (LLM arithmetic is a known
 * failure mode — the server does the math).
 */
class CompareSalesPeriods implements ToolInterface
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
        return 'sales_compare_periods';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Compares sales between two date ranges: order count, revenue and average '
            . 'order value (per currency) for each period, plus the percentage change from '
            . 'period A to period B. Canceled orders are excluded from revenue. Dates are '
            . 'compared in UTC. Typical use: "compare June to May", "this week vs the same '
            . 'week last year".';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'period_a_from' => [
                    'type' => 'string',
                    'description' => 'Start of the FIRST (earlier/baseline) period, UTC, '
                        . '"YYYY-MM-DD" or "YYYY-MM-DD HH:MM:SS".',
                ],
                'period_a_to' => [
                    'type' => 'string',
                    'description' => 'End of the first period (inclusive, whole day for '
                        . 'date-only values).',
                ],
                'period_b_from' => [
                    'type' => 'string',
                    'description' => 'Start of the SECOND (later/current) period.',
                ],
                'period_b_to' => [
                    'type' => 'string',
                    'description' => 'End of the second period. Omit for "up to now".',
                ],
            ],
            'required' => ['period_a_from', 'period_a_to', 'period_b_from'],
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
        $aFrom = DateRange::requiredDate($arguments, 'period_a_from');
        $aTo = DateRange::requiredDate($arguments, 'period_a_to');
        $bFrom = DateRange::requiredDate($arguments, 'period_b_from');
        $bTo = DateRange::optionalDate($arguments, 'period_b_to');

        $periodA = $this->summarizePeriod($aFrom, $aTo);
        $periodB = $this->summarizePeriod($bFrom, $bTo);

        return [
            'period_a' => ['from' => $aFrom, 'to' => $aTo] + $periodA,
            'period_b' => ['from' => $bFrom, 'to' => $bTo] + $periodB,
            'change_a_to_b' => $this->changes($periodA, $periodB),
        ];
    }

    /**
     * One collection pass over a period: order count, status breakdown and per-currency
     * revenue/AOV (canceled orders counted but excluded from revenue) — same rules as
     * sales_summary so the numbers of the two tools always agree.
     *
     * @return array<string, mixed>
     */
    private function summarizePeriod(string $from, ?string $to): array
    {
        $collection = $this->orderCollectionFactory->create();
        $collection->addFieldToFilter('created_at', ['gteq' => $from]);
        if ($to !== null) {
            $collection->addFieldToFilter('created_at', ['lteq' => $to]);
        }
        $collection->setPageSize(self::ORDER_SCAN_LIMIT);
        $collection->setCurPage(1);

        $ordersTotal = 0;
        $byCurrency = [];
        foreach ($collection as $order) {
            $ordersTotal++;
            if ((string)$order->getData('state') === Order::STATE_CANCELED) {
                continue;
            }
            $currency = (string)($order->getData('order_currency_code') ?: 'unknown');
            $byCurrency[$currency] ??= ['orders' => 0, 'revenue' => 0.0];
            $byCurrency[$currency]['orders']++;
            $byCurrency[$currency]['revenue'] += (float)$order->getData('grand_total');
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

        $summary = [
            'orders_total' => $ordersTotal,
            'by_currency' => $currencyRows,
        ];
        if ($ordersTotal === self::ORDER_SCAN_LIMIT) {
            $summary['truncated'] = true;
        }

        return $summary;
    }

    /**
     * Percentage deltas from period A to period B. A percentage is null when the period A
     * base is zero (no meaningful percent of nothing).
     *
     * @return array<string, mixed>
     */
    private function changes(array $periodA, array $periodB): array
    {
        $currenciesA = array_column($periodA['by_currency'], null, 'currency');
        $currenciesB = array_column($periodB['by_currency'], null, 'currency');

        $byCurrency = [];
        foreach (array_unique(array_merge(array_keys($currenciesA), array_keys($currenciesB))) as $currency) {
            $a = $currenciesA[$currency] ?? ['orders' => 0, 'revenue' => 0.0, 'avg_order_value' => 0.0];
            $b = $currenciesB[$currency] ?? ['orders' => 0, 'revenue' => 0.0, 'avg_order_value' => 0.0];
            $byCurrency[] = [
                'currency' => $currency,
                'orders_pct' => $this->pct((float)$a['orders'], (float)$b['orders']),
                'revenue_pct' => $this->pct((float)$a['revenue'], (float)$b['revenue']),
                'avg_order_value_pct' => $this->pct((float)$a['avg_order_value'], (float)$b['avg_order_value']),
            ];
        }

        return [
            'orders_total_pct' => $this->pct((float)$periodA['orders_total'], (float)$periodB['orders_total']),
            'by_currency' => $byCurrency,
        ];
    }

    /**
     * Percentage change from $a to $b, rounded to one decimal; null when $a is zero.
     */
    private function pct(float $a, float $b): ?float
    {
        if ($a == 0.0) {
            return null;
        }

        return round(($b - $a) / $a * 100, 1);
    }
}
