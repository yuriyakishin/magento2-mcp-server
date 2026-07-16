<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Tools\Category;

use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use Yu\McpServer\Model\ToolInterface;

class GetCategories implements ToolInterface
{
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
            . 'category_create or category_ids context for products.';
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
                    'description' => 'Also include disabled categories (default false).',
                ],
            ],
            'required' => [],
        ];
    }

    /**
     * Public storefront catalog structure — no ACL resource required.
     */
    public function getRequiredAclResource(): ?string
    {
        return null;
    }

    /**
     * @param array $arguments Optional `include_inactive` (bool, default false).
     * @return array{root_category_id: int, categories: array<int, array<string, mixed>>, count: int}
     */
    public function execute(array $arguments): array
    {
        $includeInactive = (bool)($arguments['include_inactive'] ?? false);
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
