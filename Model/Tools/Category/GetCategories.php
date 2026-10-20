<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Tools\Category;

use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use Yu\McpServer\Model\Auth\AdminContext;
use Yu\McpServer\Model\ContextAwareToolInterface;

/**
 * Two storefronts (see ContextAwareToolInterface): disabled categories are hidden from the
 * storefront, so `include_inactive` is honored only for an admin with the category ACL;
 * anonymous callers always get the active tree.
 */
class GetCategories implements ContextAwareToolInterface
{
    private const ADMIN_RESOURCE = 'Magento_Catalog::categories';

    public function __construct(
        private readonly CollectionFactory $categoryCollectionFactory,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'category_tree';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Returns the store category tree as a flat list ordered by path: id, parent_id, '
            . 'name, level, url_key and active flag. Use the ids as parent_id for '
            . 'category_create or category_ids context for products. Disabled categories '
            . 'are included only on request and only for an authenticated admin with '
            . 'category permission.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'include_inactive' => [
                    'type' => 'boolean',
                    'description' => 'Also include disabled categories (default false). '
                        . 'Admin only; ignored for anonymous callers.',
                ],
            ],
            'required' => [],
        ];
    }

    /**
     * Public storefront catalog structure — no ACL resource required. The admin-only
     * extras (disabled categories) are gated inside executeWithContext().
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
     * @param array $arguments Optional `include_inactive` (bool, default false).
     * @param AdminContext|null $adminContext The caller's resolved identity, or null for
     *     an anonymous request (no bearer token was supplied).
     * @return array{root_category_id: int, categories: array<int, array<string, mixed>>, count: int}
     */
    public function executeWithContext(array $arguments, ?AdminContext $adminContext): array
    {
        $includeInactive = (bool)($arguments['include_inactive'] ?? false)
            && $adminContext !== null
            && $adminContext->hasAclResource(self::ADMIN_RESOURCE);
        $rootCategoryId = (int)$this->storeManager->getStore()->getRootCategoryId();

        $collection = $this->categoryCollectionFactory->create();
        $collection->addAttributeToSelect(['name', 'url_key', 'is_active']);
        // The store's subtree only: the root category itself plus everything below it.
        $collection->addFieldToFilter('path', [
            ['eq' => '1/' . $rootCategoryId],
            ['like' => '1/' . $rootCategoryId . '/%'],
        ]);
        if (!$includeInactive) {
            $collection->addAttributeToFilter('is_active', 1);
        }
        $collection->addAttributeToSort('path', 'ASC');

        $categories = [];
        foreach ($collection as $category) {
            $categories[] = [
                'id' => (int)$category->getId(),
                'parent_id' => (int)$category->getParentId(),
                'name' => $category->getName(),
                'level' => (int)$category->getLevel(),
                'url_key' => $category->getData('url_key'),
                'is_active' => (bool)$category->getIsActive(),
            ];
        }

        return [
            'root_category_id' => $rootCategoryId,
            'categories' => $categories,
            'count' => count($categories),
        ];
    }
}
