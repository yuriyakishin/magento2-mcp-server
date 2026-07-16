<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Tools\Cms;

use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Model\BlockFactory;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Yu\McpServer\Model\WriteToolInterface;

/**
 * Creates CMS blocks. Same conventions as cms_page_create: inactive by default,
 * duplicate identifier is a hard error, never an implicit update.
 */
class CreateCmsBlock implements WriteToolInterface
{
    private const MAX_IDENTIFIER_LENGTH = 100;

    public function __construct(
        private readonly BlockFactory $blockFactory,
        private readonly BlockRepositoryInterface $blockRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'cms_block_create';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Creates a new CMS block (a content fragment rendered inside storefront '
            . 'pages). The block is created INACTIVE by default; pass "is_active": true to '
            . 'publish immediately. Fails if the identifier already exists — use '
            . 'cms_block_update to change an existing block.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'identifier' => [
                    'type' => 'string',
                    'description' => 'Unique block identifier: lowercase letters, digits, '
                        . '"-", "_", "." (max 100 characters), e.g. "delivery-terms".',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'Block title (shown in the admin panel).',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'Block body as HTML.',
                ],
                'is_active' => [
                    'type' => 'boolean',
                    'description' => 'Publish immediately (default false — created inactive '
                        . 'for human review).',
                ],
            ],
            'required' => ['identifier', 'title', 'content'],
        ];
    }

    /**
     * Magento's Blocks permission (there is no separate block-delete resource, and this
     * module ships no delete tool anyway).
     */
    public function getRequiredAclResource(): ?string
    {
        return 'Magento_Cms::block';
    }

    /**
     * @param array $arguments See getInputSchema().
     * @return array{block: array{id: int, identifier: string, title: string, is_active: bool}}
     */
    public function execute(array $arguments): array
    {
        $identifier = $this->identifierArgument($arguments);
        $title = $this->requiredStringArgument($arguments, 'title');
        $content = $this->requiredStringArgument($arguments, 'content');
        $isActive = $this->booleanArgument($arguments, 'is_active') ?? false;

        $this->assertIdentifierIsFree($identifier);

        $block = $this->blockFactory->create();
        $block->setIdentifier($identifier);
        $block->setTitle($title);
        $block->setContent($content);
        $block->setIsActive($isActive);
        // "All Store Views" — the tool creates global content; per-store scoping stays
        // admin-panel work.
        $block->setData('stores', [0]);

        $saved = $this->blockRepository->save($block);

        return [
            'block' => [
                'id' => (int)$saved->getId(),
                'identifier' => $saved->getIdentifier(),
                'title' => $saved->getTitle(),
                'is_active' => (bool)$saved->isActive(),
            ],
        ];
    }

    /**
     * Rejects the call if any block (in any store scope) already uses the identifier.
     */
    private function assertIdentifierIsFree(string $identifier): void
    {
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('identifier', $identifier)
            ->create();
        if ($this->blockRepository->getList($searchCriteria)->getTotalCount() > 0) {
            throw new \RuntimeException(sprintf(
                'CMS block with identifier "%s" already exists. Use cms_block_update to change it.',
                $identifier
            ));
        }
    }

    /**
     * Validates the "identifier" argument: format and length.
     */
    private function identifierArgument(array $arguments): string
    {
        $identifier = $this->requiredStringArgument($arguments, 'identifier');
        if (strlen($identifier) > self::MAX_IDENTIFIER_LENGTH) {
            throw new \InvalidArgumentException(sprintf(
                'Argument "identifier" must not exceed %d characters.',
                self::MAX_IDENTIFIER_LENGTH
            ));
        }
        if (!preg_match('/^[a-z0-9][a-z0-9._-]*$/', $identifier)) {
            throw new \InvalidArgumentException(
                'Argument "identifier" may contain only lowercase letters, digits, "-", "_", '
                . '"." and must start with a letter or digit.'
            );
        }

        return $identifier;
    }

    /**
     * Validates a required (or explicitly provided optional) string argument.
     */
    private function requiredStringArgument(array $arguments, string $name): string
    {
        $value = $arguments[$name] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException(
                sprintf('Argument "%s" must be a non-empty string.', $name)
            );
        }

        return trim($value);
    }

    /**
     * Validates an optional boolean argument.
     */
    private function booleanArgument(array $arguments, string $name): ?bool
    {
        if (!isset($arguments[$name])) {
            return null;
        }
        if (!is_bool($arguments[$name])) {
            throw new \InvalidArgumentException(sprintf('Argument "%s" must be a boolean.', $name));
        }

        return $arguments[$name];
    }
}
