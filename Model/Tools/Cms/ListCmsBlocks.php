<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Tools\Cms;

use Magento\Cms\Model\ResourceModel\Block\CollectionFactory as BlockCollectionFactory;
use Yu\McpServer\Model\ToolInterface;

/**
 * Inventory of CMS blocks: identifiers, titles and active flags. The listing counterpart
 * to cms_block_get/create/update.
 */
class ListCmsBlocks implements ToolInterface
{
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT = 200;

    public function __construct(
        private readonly BlockCollectionFactory $blockCollectionFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'cms_block_list';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Lists CMS blocks with identifier, title, active flag and modification dates — '
            . 'including inactive ones. Use the identifier with cms_block_get to read the '
            . 'content or with cms_block_update to edit it. Typical use: "what CMS blocks '
            . 'exist?", "find the block with the delivery terms".';
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
                    'description' => 'Only active (true) or only inactive (false) blocks. '
                        . 'Omit for all.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'How many blocks to return (default 50, max 200).',
                ],
            ],
            'required' => [],
        ];
    }

    /**
     * The listing includes inactive blocks, which the public cms_block_get deliberately
     * hides — same admin permission as the CMS block write tools.
     */
    public function getRequiredAclResource(): ?string
    {
        return 'Magento_Cms::block';
    }

    /**
     * @param array $arguments See getInputSchema().
     * @return array{total: int, blocks: array<int, array<string, mixed>>}
     */
    public function execute(array $arguments): array
    {
        $isActive = $this->isActiveArgument($arguments);
        $limit = $this->limitArgument($arguments);

        $collection = $this->blockCollectionFactory->create();
        if ($isActive !== null) {
            $collection->addFieldToFilter('is_active', $isActive ? 1 : 0);
        }
        $collection->setOrder('identifier', 'ASC');
        $collection->setPageSize($limit);
        $collection->setCurPage(1);

        $blocks = [];
        foreach ($collection as $block) {
            $blocks[] = [
                'id' => (int)$block->getId(),
                'identifier' => (string)$block->getData('identifier'),
                'title' => (string)$block->getData('title'),
                'is_active' => (bool)$block->getData('is_active'),
                'created_at' => (string)$block->getData('creation_time'),
                'updated_at' => (string)$block->getData('update_time'),
            ];
        }

        return [
            'total' => (int)$collection->getSize(),
            'blocks' => $blocks,
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
        if (!is_numeric($arguments['limit']) || (int)$arguments['limit'] < 1) {
            throw new \InvalidArgumentException('Argument "limit" must be an integer >= 1.');
        }

        return min((int)$arguments['limit'], self::MAX_LIMIT);
    }
}
