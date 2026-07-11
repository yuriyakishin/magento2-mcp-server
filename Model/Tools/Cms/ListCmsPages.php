<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Tools\Cms;

use Magento\Cms\Model\ResourceModel\Page\CollectionFactory as PageCollectionFactory;
use Yu\McpServer\Model\ToolInterface;

/**
 * Inventory of CMS pages: identifiers, titles and active flags. The listing counterpart
 * to cms_page_get/create/update — without it there is no way to discover what pages
 * exist before reading or editing one.
 */
class ListCmsPages implements ToolInterface
{
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT = 200;

    public function __construct(
        private readonly PageCollectionFactory $pageCollectionFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'cms_page_list';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Lists CMS pages with identifier, title, active flag and modification dates — '
            . 'including inactive drafts. Use the identifier with cms_page_get to read the '
            . 'content or with cms_page_update to edit it. Typical use: "what CMS pages does '
            . 'the store have?", "any inactive drafts left over?".';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'is_active' => [
                    'type' => 'boolean',
                    'description' => 'Only active (true) or only inactive (false) pages. '
                        . 'Omit for all.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'How many pages to return (default 50, max 200).',
                ],
            ],
            'required' => [],
        ];
    }

    /**
     * The listing includes inactive drafts, which the public cms_page_get deliberately
     * hides — same admin permission as the CMS page write tools' read side.
     */
    public function getRequiredAclResource(): ?string
    {
        return 'Magento_Cms::page';
    }

    /**
     * @param array $arguments See getInputSchema().
     * @return array{total: int, pages: array<int, array<string, mixed>>}
     */
    public function execute(array $arguments): array
    {
        $isActive = $this->isActiveArgument($arguments);
        $limit = $this->limitArgument($arguments);

        $collection = $this->pageCollectionFactory->create();
        if ($isActive !== null) {
            $collection->addFieldToFilter('is_active', $isActive ? 1 : 0);
        }
        $collection->setOrder('identifier', 'ASC');
        $collection->setPageSize($limit);
        $collection->setCurPage(1);

        $pages = [];
        foreach ($collection as $page) {
            $pages[] = [
                'id' => (int) $page->getId(),
                'identifier' => (string) $page->getData('identifier'),
                'title' => (string) $page->getData('title'),
                'is_active' => (bool) $page->getData('is_active'),
                'created_at' => (string) $page->getData('creation_time'),
                'updated_at' => (string) $page->getData('update_time'),
            ];
        }

        return [
            'total' => (int) $collection->getSize(),
            'pages' => $pages,
        ];
    }

    /**
     * Validates the optional "is_active" argument (null = no filter).
     */
    private function isActiveArgument(array $arguments): ?bool
    {
        if (!isset($arguments['is_active'])) {
            return null;
        }
        if (!is_bool($arguments['is_active'])) {
            throw new \InvalidArgumentException('Argument "is_active" must be a boolean.');
        }

        return $arguments['is_active'];
    }

    /**
     * Validates the optional "limit" argument.
     */
    private function limitArgument(array $arguments): int
    {
        if (!isset($arguments['limit'])) {
            return self::DEFAULT_LIMIT;
        }
        if (!is_numeric($arguments['limit']) || (int) $arguments['limit'] < 1) {
            throw new \InvalidArgumentException('Argument "limit" must be an integer >= 1.');
        }

        return min((int) $arguments['limit'], self::MAX_LIMIT);
    }
}
