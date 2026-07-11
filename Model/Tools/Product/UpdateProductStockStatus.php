<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Tools\Product;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Yu\McpServer\Model\WriteToolInterface;

/**
 * Deliberately narrow update tool: it can flip enabled/disabled and set the stock
 * quantity of existing products — nothing else. Names, prices, descriptions, categories
 * and deletion stay out of this tool's reach by design (use product_update for the
 * commercial fields; deletion has no MCP tool at all).
 */
class UpdateProductStockStatus implements WriteToolInterface
{
    private const MAX_SKUS = 50;

    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly StockRegistryInterface $stockRegistry
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'product_update_stock';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Updates ONLY the status (enabled/disabled) and/or the stock quantity of '
            . 'existing products, by SKU. Typical use: enable products that were created '
            . 'disabled, after a human reviewed them. Cannot change any other product data, '
            . 'cannot create or delete products. Fails without changes if any SKU is unknown.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'skus' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'SKUs of the products to update (1 to 50).',
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => ['enabled', 'disabled'],
                    'description' => 'New product status. Omit to leave the status unchanged.',
                ],
                'qty' => [
                    'type' => 'number',
                    'description' => 'New stock quantity (>= 0). Omit to leave the quantity '
                        . 'unchanged. Quantity > 0 also marks the product as in stock.',
                ],
            ],
            'required' => ['skus'],
        ];
    }

    /**
     * Same admin permission as creating products.
     */
    public function getRequiredAclResource(): ?string
    {
        return 'Magento_Catalog::products';
    }

    /**
     * @param array $arguments See getInputSchema().
     * @return array{updated: array<int, array{sku: string, status: string, qty: float|null}>, count: int}
     */
    public function execute(array $arguments): array
    {
        $skus = $this->skusArgument($arguments);
        $status = $this->statusArgument($arguments);
        $qty = $this->qtyArgument($arguments);

        if ($status === null && $qty === null) {
            throw new \InvalidArgumentException('Provide "status" and/or "qty" — nothing to update otherwise.');
        }

        // Resolve every product before touching any of them, so one bad SKU can't leave
        // the batch half-applied. Store id 0 is passed explicitly: this endpoint runs in
        // the frontend area, and saving a product loaded in a storefront scope would write
        // the status as a store-level override instead of the global value.
        $products = [];
        foreach ($skus as $sku) {
            try {
                $products[$sku] = $this->productRepository->get($sku, false, 0);
            } catch (NoSuchEntityException) {
                throw new \RuntimeException(
                    sprintf('Product with SKU "%s" does not exist. No products were updated.', $sku)
                );
            }
        }

        $updated = [];
        foreach ($products as $sku => $product) {
            if ($status !== null) {
                $product->setStatus($status === 'enabled' ? Status::STATUS_ENABLED : Status::STATUS_DISABLED);
                $product = $this->productRepository->save($product);
            }

            if ($qty !== null) {
                $stockItem = $this->stockRegistry->getStockItemBySku($sku);
                $stockItem->setQty($qty);
                $stockItem->setIsInStock($qty > 0);
                $this->stockRegistry->updateStockItemBySku($sku, $stockItem);
            }

            $updated[] = [
                'sku' => $sku,
                'status' => (int) $product->getStatus() === Status::STATUS_ENABLED ? 'enabled' : 'disabled',
                'qty' => $qty,
            ];
        }

        return [
            'updated' => $updated,
            'count' => count($updated),
        ];
    }

    /**
     * Validates the "skus" argument: a non-empty, de-duplicated list of SKU strings.
     *
     * @return string[]
     */
    private function skusArgument(array $arguments): array
    {
        $skus = $arguments['skus'] ?? null;
        if (!is_array($skus) || $skus === []) {
            throw new \InvalidArgumentException('Argument "skus" is required and must be a non-empty array.');
        }

        $clean = [];
        foreach ($skus as $sku) {
            if (!is_string($sku) || trim($sku) === '') {
                throw new \InvalidArgumentException('Argument "skus" must contain only non-empty strings.');
            }
            $clean[] = trim($sku);
        }
        $clean = array_values(array_unique($clean));

        if (count($clean) > self::MAX_SKUS) {
            throw new \InvalidArgumentException(
                sprintf('Argument "skus" must not contain more than %d SKUs per call.', self::MAX_SKUS)
            );
        }

        return $clean;
    }

    /**
     * Validates the optional "status" argument.
     */
    private function statusArgument(array $arguments): ?string
    {
        $status = $arguments['status'] ?? null;
        if ($status === null) {
            return null;
        }
        if (!in_array($status, ['enabled', 'disabled'], true)) {
            throw new \InvalidArgumentException('Argument "status" must be "enabled" or "disabled".');
        }

        return $status;
    }

    /**
     * Validates the optional "qty" argument.
     */
    private function qtyArgument(array $arguments): ?float
    {
        if (!isset($arguments['qty'])) {
            return null;
        }
        if (!is_numeric($arguments['qty']) || (float) $arguments['qty'] < 0) {
            throw new \InvalidArgumentException('Argument "qty" must be a number >= 0.');
        }

        return (float) $arguments['qty'];
    }
}
