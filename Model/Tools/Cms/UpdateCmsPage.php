<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Tools\Cms;

use Magento\Cms\Api\GetPageByIdentifierInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Yu\McpServer\Model\WriteToolInterface;

/**
 * Updates the content of an existing CMS page — unlike catalog data, changing page
 * text/formatting is the whole point of the CMS tools.
 * Deliberate limits: the identifier (URL) can never be changed,
 * deletion is impossible by construction (no delete tool, content must stay non-empty;
 * the closest thing is is_active=false, which is reversible), and an unknown identifier
 * is a hard error — never an implicit create.
 */
class UpdateCmsPage implements WriteToolInterface
{
    public function __construct(
        private readonly GetPageByIdentifierInterface $getPageByIdentifier,
        private readonly PageRepositoryInterface $pageRepository
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'cms_page_update';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Updates an existing CMS page found by its identifier: title, HTML content, '
            . 'content heading, meta description and/or active flag. The identifier itself '
            . 'cannot be changed and pages cannot be deleted — is_active: false only hides '
            . 'a page and is reversible. Fails if the identifier does not exist (use '
            . 'cms_page_create for new pages).';
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
                    'description' => 'Identifier of the existing CMS page to update.',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'New page title. Omit to leave unchanged.',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'New page body as HTML (replaces the whole body — '
                        . 'fetch the current one with cms_page_get first when editing). '
                        . 'Omit to leave unchanged.',
                ],
                'content_heading' => [
                    'type' => 'string',
                    'description' => 'New content heading. Omit to leave unchanged.',
                ],
                'meta_description' => [
                    'type' => 'string',
                    'description' => 'New meta description. Omit to leave unchanged.',
                ],
                'is_active' => [
                    'type' => 'boolean',
                    'description' => 'Show (true) or hide (false) the page. Omit to leave '
                        . 'unchanged.',
                ],
            ],
            'required' => ['identifier'],
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
     * @return array{page: array{id: int, identifier: string, title: string, is_active: bool, updated_fields: string[]}}
     */
    public function execute(array $arguments): array
    {
        $identifier = $this->requiredStringArgument($arguments, 'identifier');
        $changes = $this->collectChanges($arguments);
        if ($changes === []) {
            throw new \InvalidArgumentException(
                'Provide at least one of "title", "content", "content_heading", '
                . '"meta_description", "is_active" — nothing to update otherwise.'
            );
        }

        try {
            // Store id 0 is essential: with a non-zero store id the CMS resource model
            // adds an is_active=1 filter to the load select, which would make inactive
            // pages unreachable — but "edit a draft, then activate it" is exactly what
            // this tool is for.
            $page = $this->getPageByIdentifier->execute($identifier, 0);
        } catch (NoSuchEntityException) {
            throw new \RuntimeException(sprintf(
                'CMS page with identifier "%s" does not exist. Use cms_page_create for new pages.',
                $identifier
            ));
        }

        foreach ($changes as $field => $value) {
            match ($field) {
                'title' => $page->setTitle($value),
                'content' => $page->setContent($value),
                'content_heading' => $page->setContentHeading($value),
                'meta_description' => $page->setMetaDescription($value),
                'is_active' => $page->setIsActive($value),
            };
        }

        $saved = $this->pageRepository->save($page);

        return [
            'page' => [
                'id' => (int)$saved->getId(),
                'identifier' => $saved->getIdentifier(),
                'title' => $saved->getTitle(),
                'is_active' => (bool)$saved->isActive(),
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
        foreach (['title', 'content', 'content_heading', 'meta_description'] as $field) {
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
     * Validates a provided string argument: emptying a field is not allowed (a page wiped
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
