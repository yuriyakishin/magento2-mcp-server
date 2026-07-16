<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Tools\Product;

use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\CatalogInventory\Api\StockItemCriteriaInterfaceFactory;
use Magento\CatalogInventory\Api\StockItemRepositoryInterface;
use Yu\McpServer\Model\ToolInterface;

/**
 * Read-only inventory report: products whose stock quantity dropped below a threshold.
 * Complements product_update_stock — "what is running out?" → restock/disable.
 */
class GetLowStockProducts implements ToolInterface
{
    private const DEFAULT_THRESHOLD = 10.0;
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 100;

    /**
     * Stock rows scanned per call. Composite products (configurable/bundle/grouped) always
     * carry a qty=0 stock row and are filtered out only after the product types are known,
     * so the stock query over-fetches within this bound instead of using the caller's limit.
     */
    private const STOCK_SCAN_LIMIT = 500;

    public function __construct(
        private readonly StockItemCriteriaInterfaceFactory $criteriaFactory,
        private readonly StockItemRepositoryInterface $stockItemRepository,
        private readonly StockConfigurationInterface $stockConfiguration,
        private readonly CollectionFactory $productCollectionFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'product_low_stock';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Lists products whose stock quantity is below the given threshold, lowest '
            . 'quantity first. Only products with stock management enabled and a quantity of '
            . 'their own are considered (composite products like configurable or bundle are '
            . 'excluded). Requires admin access.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'threshold' => [
                    'type' => 'number',
                    'description' => 'Report products with quantity strictly below this value '
                        . '(default 10).',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of products to return (default 20, max 100).',
                ],
            ],
            'required' => [],
        ];
    }

    /**
     * Inventory levels are back-office data — same ACL as the product write tools.
     */
    public function getRequiredAclResource(): ?string
    {
        return 'Magento_Catalog::products';
    }

    /**
     * @param array $arguments Optional `threshold` (number >= 0) and `limit` (int).
     * @return array{threshold: float, products: array<int, array<string, mixed>>, count: int}
     */
    public function execute(array $arguments): array
    {
        $threshold = $this->thresholdArgument($arguments);
        $limit = $this->limitArgument($arguments);

        $criteria = $this->criteriaFactory->create();
        $criteria->setManagedFilter((bool)$this->stockConfiguration->getManageStock());
        $criteria->setQtyFilter('<', $threshold);
        $criteria->addOrder('qty', 'ASC');
        $criteria->setLimit(0, self::STOCK_SCAN_LIMIT);

        $stockItems = $this->stockItemRepository->getList($criteria)->getItems();
        if ($stockItems === []) {
            return ['threshold' => $threshold, 'products' => [], 'count' => 0];
        }

        $productsById = $this->loadProducts(
            array_map(static fn ($item) => (int)$item->getProductId(), $stockItems)
        );

        $products = [];
        foreach ($stockItems as $stockItem) {
            $product = $productsById[(int)$stockItem->getProductId()] ?? null;
            if ($product === null) {
                continue;
            }
            $products[] = [
                'sku' => $product->getSku(),
                'name' => $product->getName(),
                'qty' => (float)$stockItem->getQty(),
                'is_in_stock' => (bool)$stockItem->getIsInStock(),
                'status' => (int)$product->getStatus() === Status::STATUS_ENABLED ? 'enabled' : 'disabled',
            ];
            if (count($products) === $limit) {
                break;
            }
        }

        return [
            'threshold' => $threshold,
            'products' => $products,
            'count' => count($products),
        ];
    }

    /**
     * Loads sku/name/status for the given product ids, keyed by product id. Only product
     * types that track their own quantity are loaded — composite parents drop out here.
     *
     * @param int[] $productIds
     * @return array<int, \Magento\Catalog\Model\Product>
     */
    private function loadProducts(array $productIds): array
    {
        $qtyTypeIds = array_keys(array_filter($this->stockConfiguration->getIsQtyTypeIds()));

        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToSelect(['name', 'sku', 'status']);
        $collection->addFieldToFilter('entity_id', ['in' => $productIds]);
        $collection->addAttributeToFilter('type_id', ['in' => $qtyTypeIds]);

        $productsById = [];
        foreach ($collection as $product) {
            $productsById[(int)$product->getId()] = $product;
        }

        return $productsById;
    }

    /**
     * Validates the optional "threshold" argument.
     */
    private function thresholdArgument(array $arguments): float
    {
        if (!isset($arguments['threshold'])) {
            return self::DEFAULT_THRESHOLD;
        }
        if (!is_numeric($arguments['threshold']) || (float)$arguments['threshold'] < 0) {
            throw new \InvalidArgumentException('Argument "threshold" must be a number >= 0.');
        }

        return (float)$arguments['threshold'];
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
            throw new \InvalidArgumentException('Argument "limit" must be a positive integer.');
        }

        return min((int)$arguments['limit'], self::MAX_LIMIT);
    }
}
