<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Tools\Product;

use Magento\Catalog\Api\CategoryLinkManagementInterface;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ProductFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use Yu\McpServer\Model\WriteToolInterface;

class CreateProduct implements WriteToolInterface
{
    private const MAX_SKU_LENGTH = 64;

    public function __construct(
        private readonly ProductFactory $productFactory,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly CategoryLinkManagementInterface $categoryLinkManagement,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'product_create';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Creates a new simple product. The product is created DISABLED by default so '
            . 'a human can review it in the admin panel before it appears on the storefront; '
            . 'pass status "enabled" to publish immediately. Fails if the SKU already exists.';
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
                    'description' => 'Unique product SKU (max 64 characters).',
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'Product name.',
                ],
                'price' => [
                    'type' => 'number',
                    'description' => 'Product price in the store base currency, must be >= 0.',
                ],
                'qty' => [
                    'type' => 'number',
                    'description' => 'Initial stock quantity. Defaults to 0 (out of stock).',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Optional full product description (may contain HTML).',
                ],
                'short_description' => [
                    'type' => 'string',
                    'description' => 'Optional short description.',
                ],
                'category_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'Optional list of category IDs to assign the product to.',
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => ['enabled', 'disabled'],
                    'description' => 'Product status. Defaults to "disabled" for human review.',
                ],
                'weight' => [
                    'type' => 'number',
                    'description' => 'Optional product weight.',
                ],
            ],
            'required' => ['sku', 'name', 'price'],
        ];
    }

    /**
     * Creating products requires the admin Products permission.
     */
    public function getRequiredAclResource(): ?string
    {
        return 'Magento_Catalog::products';
    }

    /**
     * @param array $arguments See getInputSchema().
     * @return array{product: array{id: int, sku: string, name: string, price: float,
     *     status: string, qty: float, category_ids: int[]}}
     */
    public function execute(array $arguments): array
    {
        [$sku, $name, $price] = $this->requireBaseArguments($arguments);
        $qty = $this->floatArgument($arguments, 'qty', 0.0);
        $status = $this->statusArgument($arguments);
        $categoryIds = $this->categoryIdsArgument($arguments);

        $this->assertSkuIsFree($sku);

        $product = $this->productFactory->create();
        $product->setSku($sku);
        $product->setName($name);
        $product->setTypeId(Type::TYPE_SIMPLE);
        $product->setAttributeSetId((int)$product->getDefaultAttributeSetId());
        $product->setPrice($price);
        $product->setStatus($status);
        $product->setVisibility(Visibility::VISIBILITY_BOTH);
        $product->setWebsiteIds([(int)$this->storeManager->getStore()->getWebsiteId()]);
        // Read by CatalogInventory's SaveInventoryDataObserver during save — the standard
        // way to seed stock together with the product instead of a second save call.
        $product->setStockData([
            'qty' => $qty,
            'is_in_stock' => $qty > 0 ? 1 : 0,
        ]);

        if (is_string($arguments['description'] ?? null) && trim($arguments['description']) !== '') {
            $product->setCustomAttribute('description', trim($arguments['description']));
        }
        if (is_string($arguments['short_description'] ?? null) && trim($arguments['short_description']) !== '') {
            $product->setCustomAttribute('short_description', trim($arguments['short_description']));
        }

        $saved = $this->productRepository->save($product);

        if ($categoryIds !== []) {
            $this->categoryLinkManagement->assignProductToCategories($sku, $categoryIds);
        }

        return [
            'product' => [
                'id' => (int)$saved->getId(),
                'sku' => $saved->getSku(),
                'name' => $saved->getName(),
                'price' => (float)$saved->getPrice(),
                'status' => (int)$saved->getStatus() === Status::STATUS_ENABLED ? 'enabled' : 'disabled',
                'qty' => $qty,
                'category_ids' => $categoryIds,
            ],
        ];
    }

    /**
     * Validates and returns the three required arguments.
     *
     * @return array{0: string, 1: string, 2: float} sku, name, price.
     */
    private function requireBaseArguments(array $arguments): array
    {
        $sku = $arguments['sku'] ?? null;
        if (!is_string($sku) || trim($sku) === '') {
            throw new \InvalidArgumentException('Argument "sku" is required and must be a non-empty string.');
        }
        $sku = trim($sku);
        if (strlen($sku) > self::MAX_SKU_LENGTH) {
            throw new \InvalidArgumentException(
                sprintf('Argument "sku" must not exceed %d characters.', self::MAX_SKU_LENGTH)
            );
        }

        $name = $arguments['name'] ?? null;
        if (!is_string($name) || trim($name) === '') {
            throw new \InvalidArgumentException('Argument "name" is required and must be a non-empty string.');
        }

        if (!isset($arguments['price']) || !is_numeric($arguments['price']) || (float)$arguments['price'] < 0) {
            throw new \InvalidArgumentException('Argument "price" is required and must be a number >= 0.');
        }

        return [$sku, trim($name), (float)$arguments['price']];
    }

    /**
     * Returns a non-negative float argument or its default.
     */
    private function floatArgument(array $arguments, string $key, float $default): float
    {
        if (!isset($arguments[$key])) {
            return $default;
        }
        if (!is_numeric($arguments[$key]) || (float)$arguments[$key] < 0) {
            throw new \InvalidArgumentException(sprintf('Argument "%s" must be a number >= 0.', $key));
        }

        return (float)$arguments[$key];
    }

    /**
     * Maps the optional "status" argument to a catalog status id, defaulting to disabled
     * so newly created products never reach the storefront without human review.
     */
    private function statusArgument(array $arguments): int
    {
        $status = $arguments['status'] ?? 'disabled';
        if (!in_array($status, ['enabled', 'disabled'], true)) {
            throw new \InvalidArgumentException('Argument "status" must be "enabled" or "disabled".');
        }

        return $status === 'enabled' ? Status::STATUS_ENABLED : Status::STATUS_DISABLED;
    }

    /**
     * Validates the optional category_ids argument and confirms every category exists
     * before the product is created, so a bad ID can't leave a half-assigned product.
     *
     * @return int[]
     */
    private function categoryIdsArgument(array $arguments): array
    {
        $categoryIds = $arguments['category_ids'] ?? [];
        if (!is_array($categoryIds)) {
            throw new \InvalidArgumentException('Argument "category_ids" must be an array of integers.');
        }

        $ids = [];
        foreach ($categoryIds as $categoryId) {
            if (!is_numeric($categoryId)) {
                throw new \InvalidArgumentException('Argument "category_ids" must contain only integers.');
            }
            $ids[] = (int)$categoryId;
        }

        foreach ($ids as $id) {
            try {
                $this->categoryRepository->get($id);
            } catch (NoSuchEntityException) {
                throw new \RuntimeException(sprintf('Category with ID %d does not exist.', $id));
            }
        }

        return $ids;
    }

    /**
     * @throws \RuntimeException if a product with this SKU already exists — creation never
     *     silently updates an existing product.
     */
    private function assertSkuIsFree(string $sku): void
    {
        try {
            $existing = $this->productRepository->get($sku);
            throw new \RuntimeException(
                sprintf('Product with SKU "%s" already exists (ID %d).', $sku, (int)$existing->getId())
            );
        } catch (NoSuchEntityException) {
            return;
        }
    }
}
