<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Tools\Product;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Yu\McpServer\Model\Auth\AdminContext;
use Yu\McpServer\Model\ContextAwareToolInterface;

/**
 * Two storefronts (see ContextAwareToolInterface): for anonymous callers a disabled
 * product is reported as missing — the same answer a customer gets from the storefront,
 * and deliberately the same message as a truly unknown SKU, so anonymity can't be used
 * to probe which SKUs exist. An admin with the catalog ACL gets the full card either way.
 */
class GetProductDetails implements ContextAwareToolInterface
{
    private const VISIBILITY_LABELS = [
        Visibility::VISIBILITY_NOT_VISIBLE => 'not_visible',
        Visibility::VISIBILITY_IN_CATALOG => 'catalog',
        Visibility::VISIBILITY_IN_SEARCH => 'search',
        Visibility::VISIBILITY_BOTH => 'catalog_search',
    ];

    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'product_get';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Returns the full product card for one SKU: name, status, visibility, prices, '
            . 'descriptions, weight, category ids and image URLs. Use product_search first '
            . 'if you only know a part of the name. For anonymous callers disabled products '
            . 'are reported as missing (the customer view); an authenticated admin with '
            . 'catalog permission sees them.';
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
                    'description' => 'Exact SKU of the product to load.',
                ],
            ],
            'required' => ['sku'],
        ];
    }

    /**
     * Public storefront catalog data — no ACL resource required.
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
     * @param array $arguments Expects `sku` (string, required).
     * @return array{product: array<string, mixed>}
     */
    public function executeWithContext(array $arguments, ?AdminContext $adminContext): array
    {
        $sku = $arguments['sku'] ?? null;
        if (!is_string($sku) || trim($sku) === '') {
            throw new \InvalidArgumentException('Argument "sku" is required and must be a non-empty string.');
        }

        $adminView = $adminContext !== null && $adminContext->hasAclResource('Magento_Catalog::products');

        try {
            $product = $this->productRepository->get(trim($sku));
        } catch (NoSuchEntityException) {
            throw new \RuntimeException(sprintf('Product with SKU "%s" does not exist.', trim($sku)));
        }

        // A customer can't open a disabled product on the storefront, so anonymous callers
        // get the exact same answer as for an unknown SKU — existence must not be probeable.
        if (!$adminView && (int) $product->getStatus() !== Status::STATUS_ENABLED) {
            throw new \RuntimeException(sprintf('Product with SKU "%s" does not exist.', trim($sku)));
        }

        return [
            'product' => [
                'sku' => $product->getSku(),
                'name' => $product->getName(),
                'type' => $product->getTypeId(),
                'status' => (int) $product->getStatus() === Status::STATUS_ENABLED ? 'enabled' : 'disabled',
                'visibility' => self::VISIBILITY_LABELS[(int) $product->getVisibility()] ?? 'unknown',
                'price' => $product->getPrice(),
                'special_price' => $product->getData('special_price'),
                'description' => $product->getData('description'),
                'short_description' => $product->getData('short_description'),
                'url_key' => $product->getData('url_key'),
                'weight' => $product->getWeight(),
                'category_ids' => array_map('intval', $product->getCategoryIds()),
                'images' => $this->extractImages($product),
                'created_at' => $product->getCreatedAt(),
                'updated_at' => $product->getUpdatedAt(),
            ],
        ];
    }

    /**
     * Builds absolute URLs for the product's enabled media gallery images.
     *
     * @param \Magento\Catalog\Api\Data\ProductInterface $product
     * @return array<int, array{url: string, label: string|null, position: int|null, types: string[]}>
     */
    private function extractImages($product): array
    {
        $mediaBaseUrl = rtrim(
            $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA),
            '/'
        );

        $images = [];
        foreach ($product->getMediaGalleryEntries() ?? [] as $entry) {
            if ($entry->isDisabled()) {
                continue;
            }
            $images[] = [
                'url' => $mediaBaseUrl . '/catalog/product' . $entry->getFile(),
                'label' => $entry->getLabel(),
                'position' => $entry->getPosition(),
                'types' => $entry->getTypes() ?? [],
            ];
        }

        return $images;
    }
}
