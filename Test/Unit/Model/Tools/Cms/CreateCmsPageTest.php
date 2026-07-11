<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Tools\Cms;

use Magento\Cms\Api\Data\PageSearchResultsInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Model\Page;
use Magento\Cms\Model\PageFactory;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\Tools\Cms\CreateCmsPage;
use Yu\McpServer\Model\WriteToolInterface;

class CreateCmsPageTest extends TestCase
{
    /**
     * The tool must be a write tool gated by the documented "Save Page" ACL resource.
     */
    public function testDeclaresWriteToolContract(): void
    {
        $tool = new CreateCmsPage(
            $this->createMock(PageFactory::class),
            $this->createMock(PageRepositoryInterface::class),
            $this->createMock(SearchCriteriaBuilder::class)
        );

        $this->assertInstanceOf(WriteToolInterface::class, $tool);
        $this->assertSame('Magento_Cms::save', $tool->getRequiredAclResource());
        $this->assertSame('cms_page_create', $tool->getName());
    }

    /**
     * Missing required fields, a malformed identifier and a non-boolean is_active must
     * all be rejected before anything is saved.
     */
    public function testThrowsOnInvalidArguments(): void
    {
        $tool = new CreateCmsPage(
            $this->createMock(PageFactory::class),
            $this->createMock(PageRepositoryInterface::class),
            $this->createMock(SearchCriteriaBuilder::class)
        );

        foreach (
            [
                [],
                ['identifier' => 'ok-page', 'title' => 'T'],
                ['identifier' => 'ok-page', 'content' => '<p>x</p>'],
                ['identifier' => 'Bad Page!', 'title' => 'T', 'content' => '<p>x</p>'],
                ['identifier' => 'UPPER', 'title' => 'T', 'content' => '<p>x</p>'],
                ['identifier' => str_repeat('a', 101), 'title' => 'T', 'content' => '<p>x</p>'],
                ['identifier' => 'ok-page', 'title' => 'T', 'content' => '<p>x</p>', 'is_active' => 'yes'],
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
     * An already-used identifier must fail hard, never turn into an implicit update.
     */
    public function testExistingIdentifierAbortsWithoutSaving(): void
    {
        $pageRepository = $this->mockRepositoryWithExistingCount(1);
        $pageRepository->expects($this->never())->method('save');

        $tool = new CreateCmsPage(
            $this->createMock(PageFactory::class),
            $pageRepository,
            $this->mockSearchCriteriaBuilder()
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already exists');

        $tool->execute(['identifier' => 'about-us', 'title' => 'T', 'content' => '<p>x</p>']);
    }

    /**
     * A valid call creates the page INACTIVE by default, assigned to all store views.
     */
    public function testCreatesInactivePageByDefault(): void
    {
        $page = $this->createMock(Page::class);
        $page->expects($this->once())->method('setIdentifier')->with('delivery-info');
        $page->expects($this->once())->method('setTitle')->with('Delivery');
        $page->expects($this->once())->method('setContent')->with('<p>Body</p>');
        $page->expects($this->once())->method('setIsActive')->with(false);
        $page->expects($this->once())->method('setData')->with('stores', [0]);
        $page->method('getId')->willReturn(12);
        $page->method('getIdentifier')->willReturn('delivery-info');
        $page->method('getTitle')->willReturn('Delivery');
        $page->method('isActive')->willReturn(false);

        $pageFactory = $this->createMock(PageFactory::class);
        $pageFactory->method('create')->willReturn($page);

        $pageRepository = $this->mockRepositoryWithExistingCount(0);
        $pageRepository->expects($this->once())->method('save')->with($page)->willReturn($page);

        $tool = new CreateCmsPage($pageFactory, $pageRepository, $this->mockSearchCriteriaBuilder());

        $result = $tool->execute([
            'identifier' => 'delivery-info',
            'title' => 'Delivery',
            'content' => '<p>Body</p>',
        ]);

        $this->assertSame(
            ['id' => 12, 'identifier' => 'delivery-info', 'title' => 'Delivery', 'is_active' => false],
            $result['page']
        );
    }

    /**
     * Explicit is_active = true publishes the page immediately.
     */
    public function testExplicitIsActiveTruePublishesImmediately(): void
    {
        $page = $this->createMock(Page::class);
        $page->expects($this->once())->method('setIsActive')->with(true);
        $page->method('isActive')->willReturn(true);

        $pageFactory = $this->createMock(PageFactory::class);
        $pageFactory->method('create')->willReturn($page);

        $pageRepository = $this->mockRepositoryWithExistingCount(0);
        $pageRepository->method('save')->willReturn($page);

        $tool = new CreateCmsPage($pageFactory, $pageRepository, $this->mockSearchCriteriaBuilder());

        $result = $tool->execute([
            'identifier' => 'promo',
            'title' => 'Promo',
            'content' => '<p>Sale</p>',
            'is_active' => true,
        ]);

        $this->assertTrue($result['page']['is_active']);
    }

    /**
     * Builds a page repository mock whose duplicate lookup reports the given match count.
     *
     * @return PageRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private function mockRepositoryWithExistingCount(int $count): PageRepositoryInterface
    {
        $searchResults = $this->createMock(PageSearchResultsInterface::class);
        $searchResults->method('getTotalCount')->willReturn($count);
        $pageRepository = $this->createMock(PageRepositoryInterface::class);
        $pageRepository->method('getList')->willReturn($searchResults);

        return $pageRepository;
    }

    /**
     * Builds a search criteria builder mock.
     */
    private function mockSearchCriteriaBuilder(): SearchCriteriaBuilder
    {
        $searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $searchCriteriaBuilder->method('create')->willReturn($this->createMock(SearchCriteria::class));

        return $searchCriteriaBuilder;
    }
}
