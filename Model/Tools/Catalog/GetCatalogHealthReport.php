<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Tools\Catalog;

use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Yu\McpServer\Model\ToolInterface;

/**
 * Catalog quality audit in one pass: products missing images, descriptions or meta
 * descriptions, and products with a zero/empty price. Returns a count plus a sample of
 * SKUs per problem so the client can drill into (and fix, via product_update) the worst
 * offenders without pulling the whole catalog.
 */
class GetCatalogHealthReport implements ToolInterface
{
    private const DEFAULT_SAMPLE_SIZE = 10;
    private const MAX_SAMPLE_SIZE = 50;
    private const PRODUCT_SCAN_LIMIT = 10000;

    public function __construct(
        private readonly ProductCollectionFactory $productCollectionFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'catalog_health_report';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Catalog quality audit: how many products are missing an image, missing both '
            . 'descriptions, missing an SEO meta description, or have a zero/empty price — '
            . 'with sample SKUs for each problem. By default only enabled products are '
            . 'checked (those are the ones visible on the storefront); set include_disabled '
            . 'to true to audit everything. Typical use: "what needs fixing in the catalog?", '
            . 'combined with product_update to fix the findings.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'include_disabled' => [
                    'type' => 'boolean',
                    'description' => 'Also audit disabled products (default false).',
                ],
                'sample_size' => [
                    'type' => 'integer',
                    'description' => 'How many sample SKUs to list per problem '
                        . '(default 10, max 50).',
                ],
            ],
            'required' => [],
        ];
    }

    /**
     * The report reveals disabled products and back-office data quality — admin only.
     */
    public function getRequiredAclResource(): ?string
    {
        return 'Magento_Catalog::products';
    }

    /**
     * @param array $arguments See getInputSchema().
     * @return array<string, mixed>
     */
    public function execute(array $arguments): array
    {
        $includeDisabled = $this->includeDisabledArgument($arguments);
        $sampleSize = $this->sampleSizeArgument($arguments);

        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToSelect(
            ['name', 'status', 'price', 'image', 'description', 'short_description', 'meta_description']
        );
        if (!$includeDisabled) {
            $collection->addAttributeToFilter('status', Status::STATUS_ENABLED);
        }
        $collection->setPageSize(self::PRODUCT_SCAN_LIMIT);
        $collection->setCurPage(1);

        $checks = [
            'missing_image' => ['count' => 0, 'sample_skus' => []],
            'missing_description' => ['count' => 0, 'sample_skus' => []],
            'missing_meta_description' => ['count' => 0, 'sample_skus' => []],
            'zero_price' => ['count' => 0, 'sample_skus' => []],
        ];
        $scanned = 0;
        foreach ($collection as $product) {
            $scanned++;
            $sku = (string)$product->getSku();

            $image = (string)$product->getData('image');
            if ($image === '' || $image === 'no_selection') {
                $this->record($checks['missing_image'], $sku, $sampleSize);
            }
            if ($this->isBlank($product->getData('description'))
                && $this->isBlank($product->getData('short_description'))
            ) {
                $this->record($checks['missing_description'], $sku, $sampleSize);
            }
            if ($this->isBlank($product->getData('meta_description'))) {
                $this->record($checks['missing_meta_description'], $sku, $sampleSize);
            }
            $price = $product->getData('price');
            if ($price === null || (float)$price <= 0.0) {
                $this->record($checks['zero_price'], $sku, $sampleSize);
            }
        }

        $result = [
            'products_scanned' => $scanned,
            'include_disabled' => $includeDisabled,
            'checks' => $checks,
        ];
        if ($scanned === self::PRODUCT_SCAN_LIMIT) {
            $result['truncated'] = true;
        }

        return $result;
    }

    /**
     * Counts a finding and keeps the SKU while the sample list has room.
     *
     * @param array{count: int, sample_skus: string[]} $check
     */
    private function record(array &$check, string $sku, int $sampleSize): void
    {
        $check['count']++;
        if (count($check['sample_skus']) < $sampleSize) {
            $check['sample_skus'][] = $sku;
        }
    }

    /**
     * Whether an attribute value is empty for audit purposes (null, "", or whitespace).
     */
    private function isBlank(mixed $value): bool
    {
        return $value === null || trim((string)$value) === '';
    }

    /**
     * Validates the optional "include_disabled" argument.
     */
    private function includeDisabledArgument(array $arguments): bool
    {
        if (!isset($arguments['include_disabled'])) {
            return false;
        }
        if (!is_bool($arguments['include_disabled'])) {
            throw new \InvalidArgumentException('Argument "include_disabled" must be a boolean.');
        }

        return $arguments['include_disabled'];
    }

    /**
     * Validates the optional "sample_size" argument.
     */
    private function sampleSizeArgument(array $arguments): int
    {
        if (!isset($arguments['sample_size'])) {
            return self::DEFAULT_SAMPLE_SIZE;
        }
        if (!is_numeric($arguments['sample_size']) || (int)$arguments['sample_size'] < 1) {
            throw new \InvalidArgumentException('Argument "sample_size" must be an integer >= 1.');
        }

        return min((int)$arguments['sample_size'], self::MAX_SAMPLE_SIZE);
    }
}
