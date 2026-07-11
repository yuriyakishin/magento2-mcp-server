<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Tools\Category;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\CategoryFactory;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use Yu\McpServer\Model\WriteToolInterface;

class CreateCategory implements WriteToolInterface
{
    public function __construct(
        private readonly CategoryFactory $categoryFactory,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'category_create';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Creates a new catalog category. Defaults: parent is the store root category, '
            . 'category is active. Fails if a category with the same name already exists '
            . 'under the same parent.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Category name.',
                ],
                'parent_id' => [
                    'type' => 'integer',
                    'description' => 'Parent category ID. Defaults to the store root category.',
                ],
                'is_active' => [
                    'type' => 'boolean',
                    'description' => 'Whether the category is enabled. Defaults to true.',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Optional category description.',
                ],
                'url_key' => [
                    'type' => 'string',
                    'description' => 'Optional URL key. Generated from the name when omitted.',
                ],
            ],
            'required' => ['name'],
        ];
    }

    /**
     * Creating catalog structure requires the admin Categories permission.
     */
    public function getRequiredAclResource(): ?string
    {
        return 'Magento_Catalog::categories';
    }

    /**
     * @param array $arguments See getInputSchema().
     * @return array{category: array{id: int, name: string, parent_id: int, is_active: bool, path: string}}
     */
    public function execute(array $arguments): array
    {
        $name = $arguments['name'] ?? null;
        if (!is_string($name) || trim($name) === '') {
            throw new \InvalidArgumentException('Argument "name" is required and must be a non-empty string.');
        }
        $name = trim($name);

        $parentId = (int) ($arguments['parent_id'] ?? $this->storeManager->getStore()->getRootCategoryId());
        $isActive = (bool) ($arguments['is_active'] ?? true);

        try {
            $this->categoryRepository->get($parentId);
        } catch (NoSuchEntityException) {
            throw new \RuntimeException(sprintf('Parent category with ID %d does not exist.', $parentId));
        }

        $this->assertNoDuplicate($name, $parentId);

        $category = $this->categoryFactory->create();
        $category->setName($name);
        $category->setParentId($parentId);
        $category->setIsActive($isActive);

        if (is_string($arguments['description'] ?? null) && trim($arguments['description']) !== '') {
            $category->setCustomAttribute('description', trim($arguments['description']));
        }
        if (is_string($arguments['url_key'] ?? null) && trim($arguments['url_key']) !== '') {
            $category->setCustomAttribute('url_key', trim($arguments['url_key']));
        }

        $saved = $this->categoryRepository->save($category);

        return [
            'category' => [
                'id' => (int) $saved->getId(),
                'name' => $saved->getName(),
                'parent_id' => (int) $saved->getParentId(),
                'is_active' => (bool) $saved->getIsActive(),
                'path' => (string) $saved->getPath(),
            ],
        ];
    }

    /**
     * Rejects a same-name sibling: Magento itself allows duplicate names, but for an
     * AI-driven tool a hard failure is safer than silently piling up copies.
     */
    private function assertNoDuplicate(string $name, int $parentId): void
    {
        $collection = $this->categoryCollectionFactory->create();
        $collection->addAttributeToFilter('name', $name);
        $collection->addFieldToFilter('parent_id', $parentId);
        $collection->setPageSize(1);

        if ($collection->getSize() > 0) {
            throw new \RuntimeException(sprintf(
                'Category "%s" already exists under parent %d (ID %d).',
                $name,
                $parentId,
                (int) $collection->getFirstItem()->getId()
            ));
        }
    }
}
