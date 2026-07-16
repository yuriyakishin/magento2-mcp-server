<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Tools\Sales;

use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\Item\CollectionFactory as OrderItemCollectionFactory;
use Yu\McpServer\Model\ToolInterface;

/**
 * Revenue and quantity per catalog category for a period. Order items are aggregated per
 * product in PHP, then mapped onto the products' current category assignments. A product
 * in several categories counts fully in each of them (documented in the description), so
 * the per-category revenue sums can exceed the period's total revenue.
 */
class GetSalesByCategory implements ToolInterface
{
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 100;
    private const ITEM_SCAN_LIMIT = 20000;

    public function __construct(
        private readonly OrderItemCollectionFactory $orderItemCollectionFactory,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly CategoryCollectionFactory $categoryCollectionFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'sales_by_category';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Sales broken down by catalog category for a date range: revenue, quantity '
            . 'sold and distinct products per category, sorted by revenue. Products are '
            . 'mapped to their CURRENT category assignments; a product in several categories '
            . 'counts fully in each, so category revenues can add up to more than the period '
            . 'total. Items whose product was deleted are reported under "(no category)". '
            . 'Typical use: "which categories drive revenue this month?".';
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
                'limit' => [
                    'type' => 'integer',
                    'description' => 'How many categories to return (default 20, max 100).',
                ],
            ],
            'required' => ['created_from'],
        ];
    }

    /**
     * Per-category sales are order data — same admin permission as the other order tools.
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
        $limit = $this->limitArgument($arguments);

        [$perProduct, $truncated] = $this->aggregateItems($createdFrom, $createdTo);
        $categoryRows = $this->groupByCategory($perProduct);

        usort($categoryRows, static fn (array $a, array $b) => $b['revenue'] <=> $a['revenue']);

        $result = [
            'period' => ['from' => $createdFrom, 'to' => $createdTo],
            'categories' => array_slice($categoryRows, 0, $limit),
        ];
        if ($truncated) {
            $result['truncated'] = true;
        }

        return $result;
    }

    /**
     * Aggregates the period's top-level order items per product id.
     *
     * @return array{0: array<int, array{qty: float, revenue: float}>, 1: bool}
     */
    private function aggregateItems(string $createdFrom, ?string $createdTo): array
    {
        $collection = $this->orderItemCollectionFactory->create();
        $collection->addFieldToFilter('created_at', ['gteq' => $createdFrom]);
        if ($createdTo !== null) {
            $collection->addFieldToFilter('created_at', ['lteq' => $createdTo]);
        }
        $collection->addFieldToFilter('parent_item_id', ['null' => true]);
        $collection->setPageSize(self::ITEM_SCAN_LIMIT);
        $collection->setCurPage(1);

        $perProduct = [];
        $rowsScanned = 0;
        foreach ($collection as $item) {
            $rowsScanned++;
            $productId = (int)$item->getData('product_id');
            $perProduct[$productId] ??= ['qty' => 0.0, 'revenue' => 0.0];
            $perProduct[$productId]['qty'] += (float)$item->getData('qty_ordered');
            $perProduct[$productId]['revenue'] += (float)$item->getData('row_total');
        }

        return [$perProduct, $rowsScanned === self::ITEM_SCAN_LIMIT];
    }

    /**
     * Maps per-product totals onto current category assignments and resolves category
     * names. Products with no resolvable category land in "(no category)".
     *
     * @param array<int, array{qty: float, revenue: float}> $perProduct
     * @return array<int, array{category_id: int|null, name: string, revenue: float, qty: float, products: int}>
     */
    private function groupByCategory(array $perProduct): array
    {
        $byCategory = [];
        $orphan = ['category_id' => null, 'name' => '(no category)', 'revenue' => 0.0, 'qty' => 0.0, 'products' => 0];

        $categoryIdsByProduct = $this->categoryIdsByProduct(array_keys($perProduct));
        foreach ($perProduct as $productId => $totals) {
            $categoryIds = $categoryIdsByProduct[$productId] ?? [];
            if ($categoryIds === []) {
                $orphan['revenue'] += $totals['revenue'];
                $orphan['qty'] += $totals['qty'];
                $orphan['products']++;
                continue;
            }
            foreach ($categoryIds as $categoryId) {
                $byCategory[$categoryId] ??= [
                    'category_id' => $categoryId,
                    'name' => '',
                    'revenue' => 0.0,
                    'qty' => 0.0,
                    'products' => 0,
                ];
                $byCategory[$categoryId]['revenue'] += $totals['revenue'];
                $byCategory[$categoryId]['qty'] += $totals['qty'];
                $byCategory[$categoryId]['products']++;
            }
        }

        foreach ($this->categoryNames(array_keys($byCategory)) as $categoryId => $name) {
            $byCategory[$categoryId]['name'] = $name;
        }
        // A category row that got no name (deleted category) is still reported by its id.
        foreach ($byCategory as $categoryId => $row) {
            if ($row['name'] === '') {
                $byCategory[$categoryId]['name'] = sprintf('(category #%d)', $categoryId);
            }
        }

        $rows = array_values($byCategory);
        if ($orphan['products'] > 0) {
            $rows[] = $orphan;
        }

        return array_map(
            static function (array $row): array {
                $row['revenue'] = round($row['revenue'], 2);
                $row['qty'] = round($row['qty'], 2);
                return $row;
            },
            $rows
        );
    }

    /**
     * Current category ids per product id, via the product collection's category_ids.
     *
     * @param int[] $productIds
     * @return array<int, int[]>
     */
    private function categoryIdsByProduct(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }
        $collection = $this->productCollectionFactory->create();
        $collection->addIdFilter($productIds);
        $collection->addCategoryIds();

        $map = [];
        foreach ($collection as $product) {
            $map[(int)$product->getId()] = array_map('intval', (array)$product->getCategoryIds());
        }

        return $map;
    }

    /**
     * Category names for the given ids.
     *
     * @param int[] $categoryIds
     * @return array<int, string>
     */
    private function categoryNames(array $categoryIds): array
    {
        if ($categoryIds === []) {
            return [];
        }
        $collection = $this->categoryCollectionFactory->create();
        $collection->addAttributeToSelect('name');
        $collection->addIdFilter($categoryIds);

        $names = [];
        foreach ($collection as $category) {
            $names[(int)$category->getId()] = (string)$category->getName();
        }

        return $names;
    }

    /**
     * Validates the optional "limit" argument.
     */
    private function limitArgument(array $arguments): int
    {
        if (!isset($arguments['limit'])) {
            return self::DEFAULT_LIMIT;
        }
        if (!is_numeric($arguments['limit']) || (int)$arguments['limit'] < 1) {
            throw new \InvalidArgumentException('Argument "limit" must be an integer >= 1.');
        }

        return min((int)$arguments['limit'], self::MAX_LIMIT);
    }
}
