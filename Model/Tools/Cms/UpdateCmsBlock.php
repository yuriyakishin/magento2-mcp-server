<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Tools\Cms;

use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Api\GetBlockByIdentifierInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Yu\McpServer\Model\WriteToolInterface;

/**
 * Updates the content of an existing CMS block. Same rules as cms_page_update: the
 * identifier can never be changed, deletion is impossible by construction, unknown
 * identifier is a hard error — never an implicit create.
 */
class UpdateCmsBlock implements WriteToolInterface
{
    public function __construct(
        private readonly GetBlockByIdentifierInterface $getBlockByIdentifier,
        private readonly BlockRepositoryInterface $blockRepository
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'cms_block_update';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Updates an existing CMS block found by its identifier: title, HTML content '
            . 'and/or active flag. The identifier itself cannot be changed and blocks '
            . 'cannot be deleted — is_active: false only hides a block and is reversible. '
            . 'Fails if the identifier does not exist (use cms_block_create for new blocks).';
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
                    'description' => 'Identifier of the existing CMS block to update.',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'New block title. Omit to leave unchanged.',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'New block body as HTML (replaces the whole body — '
                        . 'fetch the current one with cms_block_get first when editing). '
                        . 'Omit to leave unchanged.',
                ],
                'is_active' => [
                    'type' => 'boolean',
                    'description' => 'Show (true) or hide (false) the block. Omit to leave '
                        . 'unchanged.',
                ],
            ],
            'required' => ['identifier'],
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
     * @return array{block: array{id: int, identifier: string, title: string, is_active: bool, updated_fields: string[]}}
     */
    public function execute(array $arguments): array
    {
        $identifier = $this->requiredStringArgument($arguments, 'identifier');
        $changes = $this->collectChanges($arguments);
        if ($changes === []) {
            throw new \InvalidArgumentException(
                'Provide at least one of "title", "content", "is_active" — nothing to update otherwise.'
            );
        }

        try {
            // Store id 0 is essential: with a non-zero store id the CMS resource model
            // adds an is_active=1 filter to the load select, which would make inactive
            // blocks unreachable — but "edit a draft, then activate it" is exactly what
            // this tool is for.
            $block = $this->getBlockByIdentifier->execute($identifier, 0);
        } catch (NoSuchEntityException) {
            throw new \RuntimeException(sprintf(
                'CMS block with identifier "%s" does not exist. Use cms_block_create for new blocks.',
                $identifier
            ));
        }

        foreach ($changes as $field => $value) {
            match ($field) {
                'title' => $block->setTitle($value),
                'content' => $block->setContent($value),
                'is_active' => $block->setIsActive($value),
            };
        }

        $saved = $this->blockRepository->save($block);

        return [
            'block' => [
                'id' => (int) $saved->getId(),
                'identifier' => $saved->getIdentifier(),
                'title' => $saved->getTitle(),
                'is_active' => (bool) $saved->isActive(),
                'updated_fields' => array_keys($changes),
            ],
        ];
    }

    /**
     * Extracts and validates the fields the caller wants to change.
     *
     * @return array<string, string|bool>
     */
    private function collectChanges(array $arguments): array
    {
        $changes = [];
        foreach (['title', 'content'] as $field) {
            if (isset($arguments[$field])) {
                $changes[$field] = $this->requiredStringArgument($arguments, $field);
            }
        }
        if (isset($arguments['is_active'])) {
            if (!is_bool($arguments['is_active'])) {
                throw new \InvalidArgumentException('Argument "is_active" must be a boolean.');
            }
            $changes['is_active'] = $arguments['is_active'];
        }

        return $changes;
    }

    /**
     * Validates a provided string argument: emptying a field is not allowed (a block wiped
     * to an empty body would be deletion in all but name).
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
}
