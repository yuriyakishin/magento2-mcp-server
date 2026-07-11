<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Tools\Sales;

use Magento\Sales\Model\ResourceModel\Order\Item\CollectionFactory as OrderItemCollectionFactory;
use Yu\McpServer\Model\ToolInterface;

/**
 * Best-selling products for a period, sortable by quantity or revenue. A standalone,
 * deeper version of sales_summary's top_products block: larger limit, revenue sort and
 * per-product order counts. Aggregation happens in PHP over collection rows (no raw SQL
 * per module rules), bounded by ITEM_SCAN_LIMIT.
 */
class GetBestsellers implements ToolInterface
{
    private const DEFAULT_LIMIT = 10;
    private const MAX_LIMIT = 50;
    private const ITEM_SCAN_LIMIT = 20000;

    public function __construct(
        private readonly OrderItemCollectionFactory $orderItemCollectionFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'sales_bestsellers';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Best-selling products for a date range, sorted by quantity sold or by revenue. '
            . 'Returns SKU, name, quantity, revenue and the number of distinct orders per '
            . 'product. Quantities are as ordered (cancellations not subtracted). Dates are '
            . 'compared in UTC. Typical use: "top 10 products this month", "what brought the '
            . 'most revenue last quarter?".';
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
                'sort_by' => [
                    'type' => 'string',
                    'enum' => ['qty', 'revenue'],
                    'description' => 'Sort by quantity sold (default) or by revenue.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'How many products to return (default 10, max 50).',
                ],
            ],
            'required' => ['created_from'],
        ];
    }

    /**
     * Per-product sales are order data — same admin permission as the other order tools.
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
        $sortBy = $this->sortByArgument($arguments);
        $limit = $this->limitArgument($arguments);

        $collection = $this->orderItemCollectionFactory->create();
        $collection->addFieldToFilter('created_at', ['gteq' => $createdFrom]);
        if ($createdTo !== null) {
            $collection->addFieldToFilter('created_at', ['lteq' => $createdTo]);
        }
        // Children of configurable/bundle rows would double the quantities.
        $collection->addFieldToFilter('parent_item_id', ['null' => true]);
        $collection->setPageSize(self::ITEM_SCAN_LIMIT);
        $collection->setCurPage(1);

        $products = [];
        $rowsScanned = 0;
        foreach ($collection as $item) {
            $rowsScanned++;
            $sku = (string) $item->getData('sku');
            if ($sku === '') {
                continue;
            }
            $products[$sku] ??= [
                'sku' => $sku,
                'name' => (string) $item->getData('name'),
                'qty' => 0.0,
                'revenue' => 0.0,
                'orders' => 0,
            ];
            $products[$sku]['qty'] += (float) $item->getData('qty_ordered');
            $products[$sku]['revenue'] += (float) $item->getData('row_total');
            $products[$sku]['orders']++;
        }

        usort(
            $products,
            static fn (array $a, array $b) => $b[$sortBy] <=> $a[$sortBy]
        );
        $top = array_map(
            static function (array $row): array {
                $row['qty'] = round($row['qty'], 2);
                $row['revenue'] = round($row['revenue'], 2);
                return $row;
            },
            array_slice(array_values($products), 0, $limit)
        );

        $result = [
            'period' => ['from' => $createdFrom, 'to' => $createdTo],
            'sort_by' => $sortBy,
            'products' => $top,
        ];
        if ($rowsScanned === self::ITEM_SCAN_LIMIT) {
            $result['truncated'] = true;
        }

        return $result;
    }

    /**
     * Validates the optional "sort_by" argument.
     */
    private function sortByArgument(array $arguments): string
    {
        $sortBy = $arguments['sort_by'] ?? 'qty';
        if (!in_array($sortBy, ['qty', 'revenue'], true)) {
            throw new \InvalidArgumentException('Argument "sort_by" must be "qty" or "revenue".');
        }

        return $sortBy;
    }

    /**
     * Validates the optional "limit" argument.
     */
    private function limitArgument(array $arguments): int
    {
        if (!isset($arguments['limit'])) {
            return self::DEFAULT_LIMIT;
        }
        if (!is_numeric($arguments['limit']) || (int) $arguments['limit'] < 1) {
            throw new \InvalidArgumentException('Argument "limit" must be an integer >= 1.');
        }

        return min((int) $arguments['limit'], self::MAX_LIMIT);
    }
}
