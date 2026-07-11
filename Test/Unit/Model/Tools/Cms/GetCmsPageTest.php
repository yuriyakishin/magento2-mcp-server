<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Tools\Cms;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\GetPageByIdentifierInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\Tools\Cms\GetCmsPage;

class GetCmsPageTest extends TestCase
{
    /**
     * cms_page_get is a public tool and must not require an ACL resource.
     */
    public function testRequiresNoAclResource(): void
    {
        $tool = new GetCmsPage(
            $this->createMock(GetPageByIdentifierInterface::class),
            $this->createMock(StoreManagerInterface::class)
        );

        $this->assertNull($tool->getRequiredAclResource());
        $this->assertSame('cms_page_get', $tool->getName());
    }

    /**
     * A missing or empty "identifier" argument must fail validation.
     */
    public function testThrowsWhenIdentifierArgumentIsMissing(): void
    {
        $tool = new GetCmsPage(
            $this->createMock(GetPageByIdentifierInterface::class),
            $this->createMock(StoreManagerInterface::class)
        );

        foreach ([[], ['identifier' => ''], ['identifier' => 7]] as $arguments) {
            try {
                $tool->execute($arguments);
                $this->fail('Expected InvalidArgumentException for: ' . json_encode($arguments));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * An unknown identifier is a business-logic error, reported as a RuntimeException.
     */
    public function testThrowsRuntimeExceptionForUnknownIdentifier(): void
    {
        $getPageByIdentifier = $this->createMock(GetPageByIdentifierInterface::class);
        $getPageByIdentifier->method('execute')->willThrowException(
            NoSuchEntityException::singleField('identifier', 'missing')
        );

        $tool = new GetCmsPage($getPageByIdentifier, $this->mockStoreManager());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CMS page with identifier "missing" does not exist.');

        $tool->execute(['identifier' => 'missing']);
    }

    /**
     * An inactive page is not public content and must look exactly like a missing one.
     */
    public function testInactivePageIsReportedAsMissing(): void
    {
        $page = $this->createMock(PageInterface::class);
        $page->method('isActive')->willReturn(false);

        $getPageByIdentifier = $this->createMock(GetPageByIdentifierInterface::class);
        $getPageByIdentifier->method('execute')->willReturn($page);

        $tool = new GetCmsPage($getPageByIdentifier, $this->mockStoreManager());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CMS page with identifier "draft" does not exist.');

        $tool->execute(['identifier' => 'draft']);
    }

    /**
     * An active page should be returned with its content and public URL.
     */
    public function testReturnsActivePage(): void
    {
        $page = $this->createMock(PageInterface::class);
        $page->method('isActive')->willReturn(true);
        $page->method('getId')->willReturn('7');
        $page->method('getIdentifier')->willReturn('about-us');
        $page->method('getTitle')->willReturn('About Us');
        $page->method('getContentHeading')->willReturn('Who we are');
        $page->method('getContent')->willReturn('<p>Hello</p>');
        $page->method('getMetaDescription')->willReturn('About the store');
        $page->method('getUpdateTime')->willReturn('2026-07-07 10:00:00');

        $getPageByIdentifier = $this->createMock(GetPageByIdentifierInterface::class);
        $getPageByIdentifier->method('execute')->with('about-us', 1)->willReturn($page);

        $tool = new GetCmsPage($getPageByIdentifier, $this->mockStoreManager());

        $result = $tool->execute(['identifier' => 'about-us']);

        $this->assertSame(7, $result['page']['id']);
        $this->assertSame('<p>Hello</p>', $result['page']['content']);
        $this->assertSame('https://example.com/about-us', $result['page']['url']);
    }

    /**
     * Builds a store manager mock for store id 1 with a fixed base URL.
     */
    private function mockStoreManager(): StoreManagerInterface
    {
        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn(1);
        $store->method('getBaseUrl')->willReturn('https://example.com/');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return $storeManager;
    }
}
