<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Tools\Product;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Sales\Model\ResourceModel\Order\Item\CollectionFactory as OrderItemCollectionFactory;
use Yu\McpServer\Model\ToolInterface;

/**
 * Restock forecasting: how fast a SKU sells and when the current stock runs out at that
 * pace. Joins the two halves the other tools report separately — sales volume
 * (sales_summary) and the remaining quantity (product_low_stock/product_check_stock).
 */
class GetProductSalesVelocity implements ToolInterface
{
    private const DEFAULT_DAYS = 30;
    private const MAX_DAYS = 365;

    public function __construct(
        private readonly OrderItemCollectionFactory $orderItemCollectionFactory,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly StockRegistryInterface $stockRegistry,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'product_sales_velocity';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Sales velocity and stockout forecast for one SKU: units sold over the '
            . 'look-back window (canceled quantities excluded), average units per day, '
            . 'current stock quantity, and — when the product is selling — the estimated '
            . 'number of days until the stock runs out and the projected stockout date. '
            . 'Use it for restocking decisions: "when will WS02 run out?".';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sku' => [
                    'type' => 'string',
                    'description' => 'Exact SKU of the product to analyse.',
                ],
                'days' => [
                    'type' => 'integer',
                    'description' => 'Look-back window in days for the sales pace '
                        . '(default 30, max 365).',
                ],
            ],
            'required' => ['sku'],
        ];
    }

    /**
     * Per-product sales volumes are order data — same admin permission as the other
     * sales analytics tools.
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
        $sku = $this->skuArgument($arguments);
        $days = $this->daysArgument($arguments);

        try {
            $product = $this->productRepository->get($sku);
        } catch (NoSuchEntityException) {
            throw new \RuntimeException(sprintf('Product with SKU "%s" does not exist.', $sku));
        }

        $now = $this->dateTime->gmtTimestamp();
        $from = date('Y-m-d H:i:s', $now - $days * 86400);

        [$unitsSold, $ordersCount, $revenue] = $this->sumSales($sku, $from);

        $stockQty = null;
        $isInStock = null;
        try {
            $stockItem = $this->stockRegistry->getStockItemBySku($sku);
            $stockQty = (float)$stockItem->getQty();
            $isInStock = (bool)$stockItem->getIsInStock();
        } catch (NoSuchEntityException) {
            // Composite products have no stock row of their own — report stock as unknown.
        }

        $perDay = round($unitsSold / $days, 3);

        $result = [
            'sku' => $sku,
            'name' => $product->getName(),
            'period_days' => $days,
            'units_sold' => round($unitsSold, 2),
            'orders' => $ordersCount,
            'revenue' => round($revenue, 2),
            'units_per_day' => $perDay,
            'stock_qty' => $stockQty,
            'is_in_stock' => $isInStock,
            'estimated_days_left' => null,
            'estimated_stockout_date' => null,
        ];

        if ($perDay > 0 && $stockQty !== null && $stockQty > 0) {
            $daysLeft = (int)floor($stockQty / $perDay);
            $result['estimated_days_left'] = $daysLeft;
            $result['estimated_stockout_date'] = date('Y-m-d', $now + $daysLeft * 86400);
        }

        return $result;
    }

    /**
     * Sums the SKU's sold units (net of cancellations), distinct orders and row revenue
     * since the given date. Only top-level order item rows are counted — child rows of
     * configurable/bundle items would double the quantities.
     *
     * @return array{0: float, 1: int, 2: float} [units sold, orders count, revenue]
     */
    private function sumSales(string $sku, string $from): array
    {
        $collection = $this->orderItemCollectionFactory->create();
        $collection->addFieldToFilter('sku', $sku);
        $collection->addFieldToFilter('parent_item_id', ['null' => true]);
        $collection->addFieldToFilter('created_at', ['gteq' => $from]);

        $units = 0.0;
        $revenue = 0.0;
        $orderIds = [];
        foreach ($collection as $item) {
            $net = (float)$item->getData('qty_ordered') - (float)$item->getData('qty_canceled');
            if ($net <= 0) {
                continue;
            }
            $units += $net;
            $revenue += (float)$item->getData('row_total');
            $orderIds[(int)$item->getData('order_id')] = true;
        }

        return [$units, count($orderIds), $revenue];
    }

    /**
     * Validates the required "sku" argument.
     */
    private function skuArgument(array $arguments): string
    {
        $sku = $arguments['sku'] ?? null;
        if (!is_string($sku) || trim($sku) === '') {
            throw new \InvalidArgumentException('Argument "sku" is required and must be a non-empty string.');
        }

        return trim($sku);
    }

    /**
     * Validates the optional "days" argument.
     */
    private function daysArgument(array $arguments): int
    {
        if (!isset($arguments['days'])) {
            return self::DEFAULT_DAYS;
        }
        if (!is_numeric($arguments['days']) || (int)$arguments['days'] < 1) {
            throw new \InvalidArgumentException('Argument "days" must be a positive integer.');
        }

        return min((int)$arguments['days'], self::MAX_DAYS);
    }
}
