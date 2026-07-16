<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Tools\Cms;

use Magento\Cms\Api\GetBlockByIdentifierInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use Yu\McpServer\Model\ToolInterface;

class GetCmsBlock implements ToolInterface
{
    public function __construct(
        private readonly GetBlockByIdentifierInterface $getBlockByIdentifier,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'cms_block_get';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Returns a CMS block by its identifier: title and full HTML content. CMS '
            . 'blocks are content fragments rendered inside storefront pages (delivery '
            . 'terms, contacts, banners). Only active blocks are returned.';
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
                    'description' => 'CMS block identifier, e.g. "delivery-terms".',
                ],
            ],
            'required' => ['identifier'],
        ];
    }

    /**
     * Active CMS blocks are rendered on public storefront pages — no ACL resource required.
     */
    public function getRequiredAclResource(): ?string
    {
        return null;
    }

    /**
     * @param array $arguments Expects `identifier` (string, required).
     * @return array{block: array<string, mixed>}
     */
    public function execute(array $arguments): array
    {
        $identifier = $arguments['identifier'] ?? null;
        if (!is_string($identifier) || trim($identifier) === '') {
            throw new \InvalidArgumentException('Argument "identifier" is required and must be a non-empty string.');
        }
        $identifier = trim($identifier);

        try {
            $block = $this->getBlockByIdentifier->execute(
                $identifier,
                (int)$this->storeManager->getStore()->getId()
            );
        } catch (NoSuchEntityException) {
            throw new \RuntimeException(sprintf('CMS block with identifier "%s" does not exist.', $identifier));
        }

        // A disabled block is not public content; report it exactly like a missing one.
        if (!$block->isActive()) {
            throw new \RuntimeException(sprintf('CMS block with identifier "%s" does not exist.', $identifier));
        }

        return [
            'block' => [
                'id' => (int)$block->getId(),
                'identifier' => $block->getIdentifier(),
                'title' => $block->getTitle(),
                'content' => $block->getContent(),
                'updated_at' => $block->getUpdateTime(),
            ],
        ];
    }
}
