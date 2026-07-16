<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Tools\Sales;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\Payment\CollectionFactory as PaymentCollectionFactory;
use Yu\McpServer\Model\ToolInterface;

/**
 * How customers pay: orders and revenue per payment method for a period. Two collection
 * passes (orders for the period, then their payment rows by parent_id) — the payment
 * table has no created_at of its own, so the period filter must run on the orders.
 */
class GetPaymentMethodStats implements ToolInterface
{
    private const ORDER_SCAN_LIMIT = 5000;

    public function __construct(
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly PaymentCollectionFactory $paymentCollectionFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'sales_payment_stats';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Orders and revenue per PAYMENT method for a date range (per currency): which '
            . 'payment methods customers actually use and how much revenue each brings. '
            . 'Canceled orders are excluded. Dates are compared in UTC. Typical use: "how do '
            . 'customers pay?", "what share of revenue is cash on delivery?".';
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
     * Payment usage is order data — same admin permission as the other order tools.
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

        [$orders, $truncated] = $this->ordersForPeriod($createdFrom, $createdTo);
        $methods = $this->aggregateByMethod($orders);

        usort($methods, static fn (array $a, array $b) => $b['orders'] <=> $a['orders']);

        $result = [
            'period' => ['from' => $createdFrom, 'to' => $createdTo],
            'methods' => $methods,
        ];
        if ($truncated) {
            $result['truncated'] = true;
        }

        return $result;
    }

    /**
     * The period's non-canceled orders, keyed by entity id.
     *
     * @return array{0: array<int, array{currency: string, grand_total: float}>, 1: bool}
     */
    private function ordersForPeriod(string $createdFrom, ?string $createdTo): array
    {
        $collection = $this->orderCollectionFactory->create();
        $collection->addFieldToFilter('created_at', ['gteq' => $createdFrom]);
        if ($createdTo !== null) {
            $collection->addFieldToFilter('created_at', ['lteq' => $createdTo]);
        }
        $collection->setPageSize(self::ORDER_SCAN_LIMIT);
        $collection->setCurPage(1);

        $orders = [];
        $rowsScanned = 0;
        foreach ($collection as $order) {
            $rowsScanned++;
            if ((string)$order->getData('state') === Order::STATE_CANCELED) {
                continue;
            }
            $orders[(int)$order->getData('entity_id')] = [
                'currency' => (string)($order->getData('order_currency_code') ?: 'unknown'),
                'grand_total' => (float)$order->getData('grand_total'),
            ];
        }

        return [$orders, $rowsScanned === self::ORDER_SCAN_LIMIT];
    }

    /**
     * Fetches the payment rows of the given orders and aggregates orders/revenue per
     * method and currency. The method title is taken from the payment row's
     * additional_information when present (falls back to the method code).
     *
     * @param array<int, array{currency: string, grand_total: float}> $orders
     * @return array<int, array<string, mixed>>
     */
    private function aggregateByMethod(array $orders): array
    {
        if ($orders === []) {
            return [];
        }

        $collection = $this->paymentCollectionFactory->create();
        $collection->addFieldToFilter('parent_id', ['in' => array_keys($orders)]);
        $collection->setPageSize(self::ORDER_SCAN_LIMIT);
        $collection->setCurPage(1);

        $methods = [];
        foreach ($collection as $payment) {
            $orderId = (int)$payment->getData('parent_id');
            if (!isset($orders[$orderId])) {
                continue;
            }
            $method = (string)($payment->getData('method') ?: 'unknown');
            $currency = $orders[$orderId]['currency'];
            $key = $method . '|' . $currency;

            $methods[$key] ??= [
                'method' => $method,
                'title' => $this->methodTitle($payment->getData('additional_information'), $method),
                'currency' => $currency,
                'orders' => 0,
                'revenue' => 0.0,
            ];
            $methods[$key]['orders']++;
            $methods[$key]['revenue'] += $orders[$orderId]['grand_total'];
        }

        return array_map(
            static function (array $row): array {
                $row['revenue'] = round($row['revenue'], 2);
                return $row;
            },
            array_values($methods)
        );
    }

    /**
     * Extracts the human-readable method title from the payment's additional_information
     * (JSON string or already-decoded array), falling back to the method code.
     */
    private function methodTitle(mixed $additionalInformation, string $fallback): string
    {
        if (is_string($additionalInformation)) {
            $additionalInformation = json_decode($additionalInformation, true);
        }
        if (is_array($additionalInformation)
            && is_string($additionalInformation['method_title'] ?? null)
            && $additionalInformation['method_title'] !== ''
        ) {
            return $additionalInformation['method_title'];
        }

        return $fallback;
    }
}
