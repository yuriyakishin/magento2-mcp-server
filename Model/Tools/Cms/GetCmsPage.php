<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Tools\Cms;

use Magento\Cms\Api\GetPageByIdentifierInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use Yu\McpServer\Model\ToolInterface;

class GetCmsPage implements ToolInterface
{
    public function __construct(
        private readonly GetPageByIdentifierInterface $getPageByIdentifier,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'cms_page_get';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Returns a CMS page (shipping info, returns, FAQ, about us, etc.) by its '
            . 'identifier: title, content heading, full HTML content and meta description. '
            . 'Only active pages are returned.';
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
                    'description' => 'CMS page identifier (URL key), e.g. "about-us".',
                ],
            ],
            'required' => ['identifier'],
        ];
    }

    /**
     * Active CMS pages are public storefront content — no ACL resource required.
     */
    public function getRequiredAclResource(): ?string
    {
        return null;
    }

    /**
     * @param array $arguments Expects `identifier` (string, required).
     * @return array{page: array<string, mixed>}
     */
    public function execute(array $arguments): array
    {
        $identifier = $arguments['identifier'] ?? null;
        if (!is_string($identifier) || trim($identifier) === '') {
            throw new \InvalidArgumentException('Argument "identifier" is required and must be a non-empty string.');
        }
        $identifier = trim($identifier);

        $store = $this->storeManager->getStore();
        try {
            $page = $this->getPageByIdentifier->execute($identifier, (int) $store->getId());
        } catch (NoSuchEntityException) {
            throw new \RuntimeException(sprintf('CMS page with identifier "%s" does not exist.', $identifier));
        }

        // A disabled page is not public content; report it exactly like a missing one.
        if (!$page->isActive()) {
            throw new \RuntimeException(sprintf('CMS page with identifier "%s" does not exist.', $identifier));
        }

        return [
            'page' => [
                'id' => (int) $page->getId(),
                'identifier' => $page->getIdentifier(),
                'title' => $page->getTitle(),
                'content_heading' => $page->getContentHeading(),
                'content' => $page->getContent(),
                'meta_description' => $page->getMetaDescription(),
                'url' => $store->getBaseUrl() . $page->getIdentifier(),
                'updated_at' => $page->getUpdateTime(),
            ],
        ];
    }
}
