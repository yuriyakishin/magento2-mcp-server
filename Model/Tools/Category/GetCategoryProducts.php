<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Tools\Category;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Yu\McpServer\Model\Auth\AdminContext;
use Yu\McpServer\Model\ContextAwareToolInterface;

/**
 * Two storefronts (see ContextAwareToolInterface): anonymous callers get the category's
 * enabled products only — what a customer sees; an admin with the catalog ACL also gets
 * disabled ones, each row carrying its status.
 */
class GetCategoryProducts implements ContextAwareToolInterface
{
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 100;

    public function __construct(
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly CollectionFactory $productCollectionFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'category_products';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Lists products assigned to a category by its ID (use category_tree to '
            . 'find category ids). Returns basic product data; use product_get '
            . 'for the full card of a specific product. Anonymous callers see enabled '
            . 'products only (the customer view); an authenticated admin with catalog '
            . 'permission also sees disabled ones.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'category_id' => [
                    'type' => 'integer',
                    'description' => 'Category ID from category_tree.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of products to return (default 20, max 100).',
                ],
            ],
            'required' => ['category_id'],
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
     * @param array $arguments Expects `category_id` (int, required) and optional `limit`.
     * @return array{category: array{id: int, name: string}, products: array<int, array<string, mixed>>, count: int}
     */
    public function executeWithContext(array $arguments, ?AdminContext $adminContext): array
    {
        $categoryId = $this->categoryIdArgument($arguments);
        $limit = $this->limitArgument($arguments);
        $adminView = $adminContext !== null && $adminContext->hasAclResource('Magento_Catalog::products');

        try {
            $category = $this->categoryRepository->get($categoryId);
        } catch (NoSuchEntityException) {
            throw new \RuntimeException(sprintf('Category with ID %d does not exist.', $categoryId));
        }

        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToSelect(['name', 'sku', 'price', 'status']);
        $collection->addCategoriesFilter(['in' => [$categoryId]]);
        if (!$adminView) {
            $collection->addAttributeToFilter('status', Status::STATUS_ENABLED);
        }
        $collection->setPageSize($limit);

        $products = [];
        foreach ($collection as $product) {
            $products[] = [
                'sku' => $product->getSku(),
                'name' => $product->getName(),
                'price' => $product->getPrice(),
                'status' => (int)$product->getStatus() === Status::STATUS_ENABLED ? 'enabled' : 'disabled',
            ];
        }

        return [
            'category' => [
                'id' => $categoryId,
                'name' => $category->getName(),
            ],
            'products' => $products,
            'count' => count($products),
        ];
    }

    /**
     * Validates the required "category_id" argument.
     */
    private function categoryIdArgument(array $arguments): int
    {
        $categoryId = $arguments['category_id'] ?? null;
        if (!is_numeric($categoryId) || (int)$categoryId < 1) {
            throw new \InvalidArgumentException(
                'Argument "category_id" is required and must be a positive integer.'
            );
        }

        return (int)$categoryId;
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
