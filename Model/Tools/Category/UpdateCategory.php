<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Tools\Category;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Yu\McpServer\Model\WriteToolInterface;

/**
 * Category editor following the product_update contract: a fixed list of content fields,
 * one category per call, old→new values reported per changed field. Deliberately out of
 * reach: URL key changes, moving to another parent, and root categories (level < 2) —
 * those are structural changes that stay admin-panel work.
 */
class UpdateCategory implements WriteToolInterface
{
    private const FIELDS = [
        'name',
        'description',
        'is_active',
        'include_in_menu',
        'meta_title',
        'meta_description',
    ];

    public function __construct(
        private readonly CategoryRepositoryInterface $categoryRepository
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'category_update';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Updates an existing catalog category by ID: name, description, active flag, '
            . 'menu visibility, meta title and/or meta description. Only the provided fields '
            . 'are changed; at least one is required. Cannot change the URL key, move the '
            . 'category to another parent, or edit root categories. Returns the old and new '
            . 'value of every changed field.';
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
                    'description' => 'ID of the category to update.',
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'New category name.',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'New category description (may contain HTML).',
                ],
                'is_active' => [
                    'type' => 'boolean',
                    'description' => 'Enable (true) or disable (false) the category. '
                        . 'Disabling is reversible — there is no category delete.',
                ],
                'include_in_menu' => [
                    'type' => 'boolean',
                    'description' => 'Whether the category appears in the storefront menu.',
                ],
                'meta_title' => [
                    'type' => 'string',
                    'description' => 'New SEO meta title.',
                ],
                'meta_description' => [
                    'type' => 'string',
                    'description' => 'New SEO meta description.',
                ],
            ],
            'required' => ['category_id'],
        ];
    }

    /**
     * Same admin permission as creating categories.
     */
    public function getRequiredAclResource(): ?string
    {
        return 'Magento_Catalog::categories';
    }

    /**
     * @param array $arguments See getInputSchema().
     * @return array{category_id: int, changes: array<string, array{from: mixed, to: mixed}>}
     */
    public function execute(array $arguments): array
    {
        $categoryId = $this->categoryIdArgument($arguments);
        $fields = $this->fieldArguments($arguments);

        // Store id 0 is passed explicitly: this endpoint runs in the frontend area, and a
        // category loaded in a storefront scope would save every field as a store-level
        // override instead of the global value (same contract as product_update).
        try {
            $category = $this->categoryRepository->get($categoryId, 0);
        } catch (NoSuchEntityException) {
            throw new \RuntimeException(sprintf('Category with ID %d does not exist.', $categoryId));
        }

        if ((int)$category->getLevel() < 2) {
            throw new \RuntimeException(
                sprintf('Category with ID %d is a root category and cannot be edited by this tool.', $categoryId)
            );
        }

        $changes = [];
        foreach ($fields as $field => $value) {
            $changes[$field] = [
                'from' => $this->currentValue($category, $field),
                'to' => $value,
            ];
            $this->applyField($category, $field, $value);
        }

        $this->categoryRepository->save($category);

        return [
            'category_id' => $categoryId,
            'changes' => $changes,
        ];
    }

    /**
     * Reads the value a field has before the update, normalized the same way the
     * response reports the new value.
     */
    private function currentValue(\Magento\Catalog\Api\Data\CategoryInterface $category, string $field): mixed
    {
        return match ($field) {
            'name' => $category->getName(),
            'is_active' => (bool)$category->getIsActive(),
            'include_in_menu' => (bool)$category->getData('include_in_menu'),
            default => $category->getData($field),
        };
    }

    /**
     * Writes one validated field onto the loaded category model.
     */
    private function applyField(\Magento\Catalog\Api\Data\CategoryInterface $category, string $field, mixed $value): void
    {
        match ($field) {
            'name' => $category->setName($value),
            'is_active' => $category->setIsActive($value),
            'include_in_menu' => $category->setData('include_in_menu', $value ? 1 : 0),
            default => $category->setData($field, $value),
        };
    }

    /**
     * Validates the required "category_id" argument.
     */
    private function categoryIdArgument(array $arguments): int
    {
        if (!isset($arguments['category_id']) || !is_numeric($arguments['category_id'])
            || (int)$arguments['category_id'] < 1
        ) {
            throw new \InvalidArgumentException(
                'Argument "category_id" is required and must be a positive integer.'
            );
        }

        return (int)$arguments['category_id'];
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

        if (isset($arguments['name'])) {
            if (!is_string($arguments['name']) || trim($arguments['name']) === '') {
                throw new \InvalidArgumentException('Argument "name" must be a non-empty string.');
            }
            $fields['name'] = trim($arguments['name']);
        }

        foreach (['description', 'meta_title', 'meta_description'] as $key) {
            if (!isset($arguments[$key])) {
                continue;
            }
            if (!is_string($arguments[$key])) {
                throw new \InvalidArgumentException(sprintf('Argument "%s" must be a string.', $key));
            }
            $fields[$key] = $arguments[$key];
        }

        foreach (['is_active', 'include_in_menu'] as $key) {
            if (!isset($arguments[$key])) {
                continue;
            }
            if (!is_bool($arguments[$key])) {
                throw new \InvalidArgumentException(sprintf('Argument "%s" must be a boolean.', $key));
            }
            $fields[$key] = $arguments[$key];
        }

        if ($fields === []) {
            throw new \InvalidArgumentException(sprintf(
                'Provide at least one field to update: %s.',
                implode(', ', self::FIELDS)
            ));
        }

        return $fields;
    }
}
