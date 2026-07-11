<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Tools\Cms;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\GetPageByIdentifierInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\Tools\Cms\UpdateCmsPage;
use Yu\McpServer\Model\WriteToolInterface;

class UpdateCmsPageTest extends TestCase
{
    /**
     * The tool must be a write tool gated by the documented "Save Page" ACL resource.
     */
    public function testDeclaresWriteToolContract(): void
    {
        $tool = new UpdateCmsPage(
            $this->createMock(GetPageByIdentifierInterface::class),
            $this->createMock(PageRepositoryInterface::class)
        );

        $this->assertInstanceOf(WriteToolInterface::class, $tool);
        $this->assertSame('Magento_Cms::save', $tool->getRequiredAclResource());
        $this->assertSame('cms_page_update', $tool->getName());
    }

    /**
     * A call with no updatable field, an empty content (quasi-deletion) or a non-boolean
     * is_active must be rejected before the page is even loaded.
     */
    public function testThrowsOnInvalidArguments(): void
    {
        $getPageByIdentifier = $this->createMock(GetPageByIdentifierInterface::class);
        $getPageByIdentifier->expects($this->never())->method('execute');

        $tool = new UpdateCmsPage(
            $getPageByIdentifier,
            $this->createMock(PageRepositoryInterface::class)
        );

        foreach (
            [
                [],
                ['identifier' => 'about-us'],
                ['identifier' => 'about-us', 'content' => ''],
                ['identifier' => 'about-us', 'content' => '   '],
                ['identifier' => 'about-us', 'is_active' => 'no'],
            ] as $arguments
        ) {
            try {
                $tool->execute($arguments);
                $this->fail('Expected InvalidArgumentException for: ' . json_encode($arguments));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * An unknown identifier must fail hard, never turn into an implicit create.
     */
    public function testUnknownIdentifierAbortsWithoutSaving(): void
    {
        $getPageByIdentifier = $this->createMock(GetPageByIdentifierInterface::class);
        $getPageByIdentifier->method('execute')->willThrowException(
            NoSuchEntityException::singleField('identifier', 'missing')
        );

        $pageRepository = $this->createMock(PageRepositoryInterface::class);
        $pageRepository->expects($this->never())->method('save');

        $tool = new UpdateCmsPage($getPageByIdentifier, $pageRepository);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CMS page with identifier "missing" does not exist.');

        $tool->execute(['identifier' => 'missing', 'title' => 'New Title']);
    }

    /**
     * Only the provided fields are changed; everything else stays untouched.
     */
    public function testUpdatesOnlyProvidedFields(): void
    {
        $page = $this->createMock(PageInterface::class);
        $page->expects($this->once())->method('setTitle')->with('New Title');
        $page->expects($this->once())->method('setContent')->with('<p>New body</p>');
        $page->expects($this->never())->method('setContentHeading');
        $page->expects($this->never())->method('setMetaDescription');
        $page->expects($this->never())->method('setIsActive');
        $page->expects($this->never())->method('setIdentifier');
        $page->method('getId')->willReturn('7');
        $page->method('getIdentifier')->willReturn('about-us');
        $page->method('getTitle')->willReturn('New Title');
        $page->method('isActive')->willReturn(true);

        $getPageByIdentifier = $this->createMock(GetPageByIdentifierInterface::class);
        $getPageByIdentifier->method('execute')->with('about-us', 0)->willReturn($page);

        $pageRepository = $this->createMock(PageRepositoryInterface::class);
        $pageRepository->expects($this->once())->method('save')->with($page)->willReturn($page);

        $tool = new UpdateCmsPage($getPageByIdentifier, $pageRepository);

        $result = $tool->execute([
            'identifier' => 'about-us',
            'title' => 'New Title',
            'content' => '<p>New body</p>',
        ]);

        $this->assertSame(7, $result['page']['id']);
        $this->assertSame(['title', 'content'], $result['page']['updated_fields']);
        $this->assertTrue($result['page']['is_active']);
    }

    /**
     * is_active = false hides the page (reversible) — the only sanctioned way to
     * "remove" content, since deletion has no tool.
     */
    public function testCanDeactivatePage(): void
    {
        $page = $this->createMock(PageInterface::class);
        $page->expects($this->once())->method('setIsActive')->with(false);
        $page->method('isActive')->willReturn(false);

        $getPageByIdentifier = $this->createMock(GetPageByIdentifierInterface::class);
        $getPageByIdentifier->method('execute')->willReturn($page);

        $pageRepository = $this->createMock(PageRepositoryInterface::class);
        $pageRepository->method('save')->willReturn($page);

        $tool = new UpdateCmsPage($getPageByIdentifier, $pageRepository);

        $result = $tool->execute(['identifier' => 'old-promo', 'is_active' => false]);

        $this->assertFalse($result['page']['is_active']);
        $this->assertSame(['is_active'], $result['page']['updated_fields']);
    }
}
