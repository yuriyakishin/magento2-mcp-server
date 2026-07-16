<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Tools\Cms;

use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Model\PageFactory;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Yu\McpServer\Model\WriteToolInterface;

/**
 * Creates CMS pages. Like product_create, new pages are inactive by default so a human
 * can review them before they appear on the storefront. Duplicates fail hard — an
 * existing identifier is never an implicit update (that's cms_page_update's job, and
 * creation and modification are different permissions).
 */
class CreateCmsPage implements WriteToolInterface
{
    private const MAX_IDENTIFIER_LENGTH = 100;

    public function __construct(
        private readonly PageFactory $pageFactory,
        private readonly PageRepositoryInterface $pageRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'cms_page_create';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Creates a new CMS page with HTML content. The page is created INACTIVE by '
            . 'default so a human can review it before it appears on the storefront; pass '
            . '"is_active": true to publish immediately. Fails if the identifier already '
            . 'exists — use cms_page_update to change an existing page.';
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
                    'description' => 'Unique URL key: lowercase letters, digits, "-", "_", '
                        . '".", "/" (max 100 characters), e.g. "delivery-info".',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'Page title.',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'Page body as HTML.',
                ],
                'content_heading' => [
                    'type' => 'string',
                    'description' => 'Optional heading rendered above the content.',
                ],
                'meta_description' => [
                    'type' => 'string',
                    'description' => 'Optional meta description for search engines.',
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
     * Magento's "Save Page" permission (deletion stays impossible: there is no delete tool,
     * and Magento_Cms::page_delete is never requested).
     */
    public function getRequiredAclResource(): ?string
    {
        return 'Magento_Cms::save';
    }

    /**
     * @param array $arguments See getInputSchema().
     * @return array{page: array{id: int, identifier: string, title: string, is_active: bool}}
     */
    public function execute(array $arguments): array
    {
        $identifier = $this->identifierArgument($arguments);
        $title = $this->requiredStringArgument($arguments, 'title');
        $content = $this->requiredStringArgument($arguments, 'content');
        $isActive = $this->booleanArgument($arguments, 'is_active') ?? false;

        $this->assertIdentifierIsFree($identifier);

        $page = $this->pageFactory->create();
        $page->setIdentifier($identifier);
        $page->setTitle($title);
        $page->setContent($content);
        $page->setIsActive($isActive);
        if (isset($arguments['content_heading'])) {
            $page->setContentHeading($this->requiredStringArgument($arguments, 'content_heading'));
        }
        if (isset($arguments['meta_description'])) {
            $page->setMetaDescription($this->requiredStringArgument($arguments, 'meta_description'));
        }
        // "All Store Views" — the tool creates global content; per-store scoping stays
        // admin-panel work.
        $page->setData('stores', [0]);

        $saved = $this->pageRepository->save($page);

        return [
            'page' => [
                'id' => (int)$saved->getId(),
                'identifier' => $saved->getIdentifier(),
                'title' => $saved->getTitle(),
                'is_active' => (bool)$saved->isActive(),
            ],
        ];
    }

    /**
     * Rejects the call if any page (in any store scope) already uses the identifier.
     */
    private function assertIdentifierIsFree(string $identifier): void
    {
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('identifier', $identifier)
            ->create();
        if ($this->pageRepository->getList($searchCriteria)->getTotalCount() > 0) {
            throw new \RuntimeException(sprintf(
                'CMS page with identifier "%s" already exists. Use cms_page_update to change it.',
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
        if (!preg_match('#^[a-z0-9][a-z0-9._/-]*$#', $identifier)) {
            throw new \InvalidArgumentException(
                'Argument "identifier" may contain only lowercase letters, digits, "-", "_", '
                . '".", "/" and must start with a letter or digit.'
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
