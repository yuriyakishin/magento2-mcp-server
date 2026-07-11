<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Tools\Product;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Yu\McpServer\Model\Auth\AdminContext;
use Yu\McpServer\Model\ContextAwareToolInterface;

/**
 * Two storefronts (see ContextAwareToolInterface): for anonymous callers a disabled
 * product's stock is as invisible as the product itself — reported "found": false, same
 * as an unknown SKU. An admin with the catalog ACL sees the stock of any product, with
 * its status alongside.
 */
class CheckStock implements ContextAwareToolInterface
{
    private const MAX_SKUS = 50;

    public function __construct(
        private readonly StockRegistryInterface $stockRegistry,
        private readonly ProductRepositoryInterface $productRepository
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'product_check_stock';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Checks stock availability for one or more SKUs: quantity and in-stock flag '
            . 'per SKU. Unknown SKUs are reported per item ("found": false) without failing '
            . 'the whole call. For anonymous callers disabled products are reported as not '
            . 'found (the customer view); an authenticated admin with catalog permission '
            . 'sees them with their status.';
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
                    'description' => 'SKUs to check (1 to 50).',
                ],
            ],
            'required' => ['skus'],
        ];
    }

    /**
     * Availability is shown on the storefront — no ACL resource required.
     */
    public function getRequiredAclResource(): ?string
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        return $this->executeWithContext($arguments, null);
    }

    /**
     * @param array $arguments Expects `skus` (string[], required).
     * @return array{stock: array<int, array<string, mixed>>, count: int}
     */
    public function executeWithContext(array $arguments, ?AdminContext $adminContext): array
    {
        $skus = $this->skusArgument($arguments);
        $adminView = $adminContext !== null && $adminContext->hasAclResource('Magento_Catalog::products');

        $stock = [];
        foreach ($skus as $sku) {
            try {
                $product = $this->productRepository->get($sku);
                $enabled = (int) $product->getStatus() === Status::STATUS_ENABLED;

                // A customer can't see a disabled product on the storefront, so its stock
                // is answered exactly like an unknown SKU for anonymous callers.
                if (!$adminView && !$enabled) {
                    $stock[] = ['sku' => $sku, 'found' => false];
                    continue;
                }

                $stockItem = $this->stockRegistry->getStockItemBySku($sku);
                $row = [
                    'sku' => $sku,
                    'found' => true,
                    'qty' => (float) $stockItem->getQty(),
                    'is_in_stock' => (bool) $stockItem->getIsInStock(),
                ];
                if ($adminView) {
                    $row['status'] = $enabled ? 'enabled' : 'disabled';
                }
                $stock[] = $row;
            } catch (NoSuchEntityException) {
                $stock[] = [
                    'sku' => $sku,
                    'found' => false,
                ];
            }
        }

        return [
            'stock' => $stock,
            'count' => count($stock),
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
}
