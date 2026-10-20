<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Tools\Product;

use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Yu\McpServer\Model\Auth\AdminContext;
use Yu\McpServer\Model\ContextAwareToolInterface;
use Yu\McpServer\Model\Tools\Catalog\StorefrontScope;

/**
 * Two storefronts (see ContextAwareToolInterface): anonymous callers find exactly what the
 * storefront search shows a customer (see StorefrontScope); an admin with the catalog ACL
 * finds every product, each row carrying its status so the assistant can tell them apart.
 */
class SearchProducts implements ContextAwareToolInterface
{
    private const DEFAULT_LIMIT = 10;
    private const MAX_LIMIT = 50;

    public function __construct(
        private readonly CollectionFactory $productCollectionFactory,
        private readonly StorefrontScope $storefrontScope
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'product_search';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Searches storefront products by name or SKU and returns basic product data. '
            . 'Anonymous callers find what the storefront search shows a customer of this '
            . 'store (enabled, assigned to this website, visible in search); an '
            . 'authenticated admin with catalog permission finds every product, marked with '
            . 'its status.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Search text matched against product name and SKU.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of products to return (default 10, max 50).',
                ],
            ],
            'required' => ['query'],
        ];
    }

    /**
     * Public storefront catalog data — no ACL resource required. The admin-only extras
     * (disabled products) are gated inside executeWithContext(), not here.
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
     * @param array $arguments Expects `query` (string, required) and optional `limit` (int).
     * @param AdminContext|null $adminContext The caller's resolved identity, or null for
     *     an anonymous request (no bearer token was supplied).
     * @return array{products: array<int, array<string, mixed>>}
     */
    public function executeWithContext(array $arguments, ?AdminContext $adminContext): array
    {
        $query = $arguments['query'] ?? null;
        if (!is_string($query) || trim($query) === '') {
            throw new \InvalidArgumentException('Argument "query" is required and must be a non-empty string.');
        }

        $limit = (int)($arguments['limit'] ?? self::DEFAULT_LIMIT);
        $limit = max(1, min($limit, self::MAX_LIMIT));

        $adminView = $adminContext !== null && $adminContext->hasAclResource('Magento_Catalog::products');

        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToSelect(['name', 'sku', 'price', 'status']);
        $collection->addAttributeToFilter([
            ['attribute' => 'name', 'like' => '%' . $query . '%'],
            ['attribute' => 'sku', 'like' => '%' . $query . '%'],
        ]);
        if (!$adminView) {
            $this->storefrontScope->filterProductCollection($collection, StorefrontScope::VISIBLE_IN_SEARCH);
        }
        $collection->setPageSize($limit);

        $products = [];
        foreach ($collection as $product) {
            $row = [
                'sku' => $product->getSku(),
                'name' => $product->getName(),
                'price' => $product->getPrice(),
            ];
            if ($adminView) {
                $row['status'] = (int)$product->getStatus() === Status::STATUS_ENABLED ? 'enabled' : 'disabled';
            }
            $products[] = $row;
        }

        return ['products' => $products];
    }
}
