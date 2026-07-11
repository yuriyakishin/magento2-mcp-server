<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Tools\Product;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Framework\Exception\NoSuchEntityException;
use Yu\McpServer\Model\WriteToolInterface;

/**
 * General product editor. It may change the commercial fields listed in FIELDS and
 * nothing else: no SKU/type/attribute-set changes,
 * no category assignment, no images, no deletion. One product per call — bulk edits go
 * through repeated calls, keeping every change individually audit-logged.
 */
class UpdateProduct implements WriteToolInterface
{
    /**
     * Editable fields. name/price/status use the declared ProductInterface setters;
     * the rest are EAV attributes without interface accessors and go through setData().
     */
    private const FIELDS = ['name', 'price', 'special_price', 'description', 'short_description', 'status'];

    public function __construct(
        private readonly ProductRepositoryInterface $productRepository
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'product_update';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Updates an existing product by SKU: name, price, special (sale) price, '
            . 'description, short description and/or enabled status. Only the provided '
            . 'fields are changed; at least one is required. Set remove_special_price to '
            . 'true to clear a sale price. Cannot change the SKU itself, categories, images '
            . 'or stock (use product_update_stock for quantity). Returns the old and '
            . 'new value of every changed field.';
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
                    'description' => 'Exact SKU of the product to update.',
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'New product name.',
                ],
                'price' => [
                    'type' => 'number',
                    'description' => 'New regular price (> 0).',
                ],
                'special_price' => [
                    'type' => 'number',
                    'description' => 'New special (sale) price (> 0, below the regular price).',
                ],
                'remove_special_price' => [
                    'type' => 'boolean',
                    'description' => 'Set true to remove the special price entirely.',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'New full description (may contain HTML).',
                ],
                'short_description' => [
                    'type' => 'string',
                    'description' => 'New short description.',
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => ['enabled', 'disabled'],
                    'description' => 'New product status.',
                ],
            ],
            'required' => ['sku'],
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
     * @return array{sku: string, changes: array<string, array{from: mixed, to: mixed}>}
     */
    public function execute(array $arguments): array
    {
        $sku = $this->skuArgument($arguments);
        $fields = $this->fieldArguments($arguments);

        // Store id 0 is passed explicitly: this endpoint runs in the frontend area, and a
        // product loaded in a storefront scope would save every field as a store-level
        // override instead of the global value (same contract as product_update_stock).
        try {
            $product = $this->productRepository->get($sku, false, 0);
        } catch (NoSuchEntityException) {
            throw new \RuntimeException(sprintf('Product with SKU "%s" does not exist.', $sku));
        }

        if (isset($fields['special_price'])) {
            $newPrice = $fields['price'] ?? (float) $product->getPrice();
            if ($fields['special_price'] >= $newPrice) {
                throw new \InvalidArgumentException(sprintf(
                    'Argument "special_price" (%s) must be below the regular price (%s).',
                    $fields['special_price'],
                    $newPrice
                ));
            }
        }

        $changes = [];
        foreach ($fields as $field => $value) {
            $old = $this->currentValue($product, $field);
            $new = $field === 'remove_special_price' ? null : $value;
            $this->applyField($product, $field, $value);
            $changes[$field === 'remove_special_price' ? 'special_price' : $field] = [
                'from' => $old,
                'to' => $new,
            ];
        }

        $this->productRepository->save($product);

        return [
            'sku' => $sku,
            'changes' => $changes,
        ];
    }

    /**
     * Reads the value a field has before the update, normalized the same way the
     * response reports the new value.
     */
    private function currentValue(\Magento\Catalog\Api\Data\ProductInterface $product, string $field): mixed
    {
        return match ($field) {
            'name' => $product->getName(),
            'price' => $product->getPrice() === null ? null : (float) $product->getPrice(),
            'special_price', 'remove_special_price' => $product->getData('special_price') === null
                ? null
                : (float) $product->getData('special_price'),
            'status' => (int) $product->getStatus() === Status::STATUS_ENABLED ? 'enabled' : 'disabled',
            default => $product->getData($field),
        };
    }

    /**
     * Writes one validated field onto the loaded product model.
     */
    private function applyField(\Magento\Catalog\Api\Data\ProductInterface $product, string $field, mixed $value): void
    {
        match ($field) {
            'name' => $product->setName($value),
            'price' => $product->setPrice($value),
            'status' => $product->setStatus(
                $value === 'enabled' ? Status::STATUS_ENABLED : Status::STATUS_DISABLED
            ),
            'special_price' => $product->setData('special_price', $value),
            'remove_special_price' => $product->setData('special_price', null),
            default => $product->setData($field, $value),
        };
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
     * Validates every provided editable field and returns the map of fields to apply.
     * Throws when no editable field is provided at all.
     *
     * @return array<string, mixed>
     */
    private function fieldArguments(array $arguments): array
    {
        $fields = [];

        foreach (['name', 'description', 'short_description'] as $key) {
            if (!isset($arguments[$key])) {
                continue;
            }
            if (!is_string($arguments[$key]) || trim($arguments[$key]) === '') {
                throw new \InvalidArgumentException(
                    sprintf('Argument "%s" must be a non-empty string.', $key)
                );
            }
            $fields[$key] = $arguments[$key];
        }

        foreach (['price', 'special_price'] as $key) {
            if (!isset($arguments[$key])) {
                continue;
            }
            if (!is_numeric($arguments[$key]) || (float) $arguments[$key] <= 0) {
                throw new \InvalidArgumentException(sprintf('Argument "%s" must be a number > 0.', $key));
            }
            $fields[$key] = (float) $arguments[$key];
        }

        if (isset($arguments['status'])) {
            if (!in_array($arguments['status'], ['enabled', 'disabled'], true)) {
                throw new \InvalidArgumentException('Argument "status" must be "enabled" or "disabled".');
            }
            $fields['status'] = $arguments['status'];
        }

        if (isset($arguments['remove_special_price'])) {
            if (!is_bool($arguments['remove_special_price'])) {
                throw new \InvalidArgumentException('Argument "remove_special_price" must be a boolean.');
            }
            if ($arguments['remove_special_price']) {
                if (isset($fields['special_price'])) {
                    throw new \InvalidArgumentException(
                        'Arguments "special_price" and "remove_special_price" are mutually exclusive.'
                    );
                }
                $fields['remove_special_price'] = true;
            }
        }

        if ($fields === []) {
            throw new \InvalidArgumentException(sprintf(
                'Provide at least one field to update: %s or remove_special_price.',
                implode(', ', self::FIELDS)
            ));
        }

        return $fields;
    }
}
