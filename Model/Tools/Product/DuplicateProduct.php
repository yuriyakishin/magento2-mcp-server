<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Tools\Product;

use Magento\Catalog\Api\CategoryLinkManagementInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Model\ProductFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Yu\McpServer\Model\WriteToolInterface;

/**
 * Duplicates a simple product under a new SKU: copies the commercial and SEO fields plus
 * category assignments, always creates the copy DISABLED with zero stock (same
 * human-review contract as product_create). Deliberately a field-by-field copy rather
 * than Magento's Copier: the copied field list stays explicit and auditable.
 */
class DuplicateProduct implements WriteToolInterface
{
    private const MAX_SKU_LENGTH = 64;

    /**
     * EAV attributes copied verbatim from the source when they have a value.
     */
    private const COPIED_ATTRIBUTES = [
        'description',
        'short_description',
        'meta_title',
        'meta_keyword',
        'meta_description',
        'tax_class_id',
        'weight',
    ];

    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ProductFactory $productFactory,
        private readonly CategoryLinkManagementInterface $categoryLinkManagement
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'product_duplicate';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Duplicates an existing simple product under a new SKU: copies name, price, '
            . 'descriptions, SEO fields, weight, tax class, website and category assignments. '
            . 'The copy is ALWAYS created disabled with zero stock so a human can adjust it '
            . 'in the admin panel (or via product_update) before it goes live. Typical use: '
            . 'creating product variations from an existing item.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'source_sku' => [
                    'type' => 'string',
                    'description' => 'SKU of the product to duplicate.',
                ],
                'new_sku' => [
                    'type' => 'string',
                    'description' => 'SKU for the copy (max 64 characters, must not exist).',
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'Name for the copy. Defaults to the source name plus '
                        . '" (copy)".',
                ],
                'price' => [
                    'type' => 'number',
                    'description' => 'Price for the copy (> 0). Defaults to the source price.',
                ],
            ],
            'required' => ['source_sku', 'new_sku'],
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
     * @return array{product: array<string, mixed>}
     */
    public function execute(array $arguments): array
    {
        $sourceSku = $this->skuArgument($arguments, 'source_sku');
        $newSku = $this->skuArgument($arguments, 'new_sku');
        if ($newSku === $sourceSku) {
            throw new \InvalidArgumentException('Arguments "source_sku" and "new_sku" must differ.');
        }
        $name = $this->nameArgument($arguments);
        $price = $this->priceArgument($arguments);

        // Store id 0: copy the global attribute values, not a storefront scope's overrides.
        try {
            $source = $this->productRepository->get($sourceSku, false, 0);
        } catch (NoSuchEntityException) {
            throw new \RuntimeException(sprintf('Product with SKU "%s" does not exist.', $sourceSku));
        }
        if ($source->getTypeId() !== Type::TYPE_SIMPLE) {
            throw new \RuntimeException(sprintf(
                'Product "%s" is of type "%s" — only simple products can be duplicated.',
                $sourceSku,
                $source->getTypeId()
            ));
        }
        $this->assertSkuIsFree($newSku);

        $product = $this->productFactory->create();
        $product->setSku($newSku);
        $product->setName($name ?? $source->getName() . ' (copy)');
        $product->setTypeId(Type::TYPE_SIMPLE);
        $product->setAttributeSetId((int)$source->getAttributeSetId());
        $product->setPrice($price ?? (float)$source->getPrice());
        $product->setStatus(Status::STATUS_DISABLED);
        $product->setVisibility((int)$source->getVisibility());
        $product->setWebsiteIds(array_map('intval', (array)$source->getWebsiteIds()));
        $product->setStockData([
            'qty' => 0,
            'is_in_stock' => 0,
        ]);
        foreach (self::COPIED_ATTRIBUTES as $attribute) {
            $value = $source->getData($attribute);
            if ($value !== null && $value !== '') {
                $product->setData($attribute, $value);
            }
        }

        $saved = $this->productRepository->save($product);

        $categoryIds = array_map('intval', (array)$source->getCategoryIds());
        if ($categoryIds !== []) {
            $this->categoryLinkManagement->assignProductToCategories($newSku, $categoryIds);
        }

        return [
            'product' => [
                'id' => (int)$saved->getId(),
                'sku' => $saved->getSku(),
                'name' => $saved->getName(),
                'price' => (float)$saved->getPrice(),
                'status' => 'disabled',
                'source_sku' => $sourceSku,
                'category_ids' => $categoryIds,
            ],
        ];
    }

    /**
     * Validates a required SKU argument.
     */
    private function skuArgument(array $arguments, string $key): string
    {
        $sku = $arguments[$key] ?? null;
        if (!is_string($sku) || trim($sku) === '') {
            throw new \InvalidArgumentException(
                sprintf('Argument "%s" is required and must be a non-empty string.', $key)
            );
        }
        $sku = trim($sku);
        if (strlen($sku) > self::MAX_SKU_LENGTH) {
            throw new \InvalidArgumentException(
                sprintf('Argument "%s" must not exceed %d characters.', $key, self::MAX_SKU_LENGTH)
            );
        }

        return $sku;
    }

    /**
     * Validates the optional "name" argument.
     */
    private function nameArgument(array $arguments): ?string
    {
        if (!isset($arguments['name'])) {
            return null;
        }
        if (!is_string($arguments['name']) || trim($arguments['name']) === '') {
            throw new \InvalidArgumentException('Argument "name" must be a non-empty string.');
        }

        return trim($arguments['name']);
    }

    /**
     * Validates the optional "price" argument.
     */
    private function priceArgument(array $arguments): ?float
    {
        if (!isset($arguments['price'])) {
            return null;
        }
        if (!is_numeric($arguments['price']) || (float)$arguments['price'] <= 0) {
            throw new \InvalidArgumentException('Argument "price" must be a number > 0.');
        }

        return (float)$arguments['price'];
    }

    /**
     * @throws \RuntimeException if a product with this SKU already exists — duplication
     *     never silently updates an existing product.
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
