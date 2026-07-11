<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Tools\Product;

use Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\Auth\AdminContext;
use Yu\McpServer\Model\Tools\Product\GetProductDetails;

class GetProductDetailsTest extends TestCase
{
    /**
     * product_get is a public tool and must not require an ACL resource.
     */
    public function testRequiresNoAclResource(): void
    {
        $tool = new GetProductDetails(
            $this->createMock(ProductRepositoryInterface::class),
            $this->createMock(StoreManagerInterface::class)
        );

        $this->assertNull($tool->getRequiredAclResource());
        $this->assertSame('product_get', $tool->getName());
        $this->assertSame(['sku'], $tool->getInputSchema()['required']);
    }

    /**
     * A missing or empty "sku" argument must fail validation.
     */
    public function testThrowsWhenSkuArgumentIsMissing(): void
    {
        $tool = new GetProductDetails(
            $this->createMock(ProductRepositoryInterface::class),
            $this->createMock(StoreManagerInterface::class)
        );

        foreach ([[], ['sku' => ''], ['sku' => '   '], ['sku' => 42]] as $arguments) {
            try {
                $tool->execute($arguments);
                $this->fail('Expected InvalidArgumentException for: ' . json_encode($arguments));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * An unknown SKU is a business-logic error, reported as a RuntimeException.
     */
    public function testThrowsRuntimeExceptionForUnknownSku(): void
    {
        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('get')->willThrowException(
            NoSuchEntityException::singleField('sku', 'missing')
        );

        $tool = new GetProductDetails(
            $productRepository,
            $this->createMock(StoreManagerInterface::class)
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Product with SKU "missing" does not exist.');

        $tool->execute(['sku' => 'missing']);
    }

    /**
     * A valid SKU should return the full product card with absolute image URLs,
     * skipping disabled gallery entries.
     */
    public function testReturnsProductCardForValidSku(): void
    {
        $visibleImage = $this->mockGalleryEntry(false, '/a/b/visible.jpg', 'Front', 1, ['image', 'small_image']);
        $hiddenImage = $this->mockGalleryEntry(true, '/a/b/hidden.jpg', 'Old', 2, []);

        $product = $this->createMock(Product::class);
        $product->method('getSku')->willReturn('SKU-1');
        $product->method('getName')->willReturn('Test Product');
        $product->method('getTypeId')->willReturn('simple');
        $product->method('getStatus')->willReturn(Status::STATUS_ENABLED);
        $product->method('getVisibility')->willReturn(Visibility::VISIBILITY_BOTH);
        $product->method('getPrice')->willReturn(19.99);
        $product->method('getWeight')->willReturn('1.5');
        $product->method('getCategoryIds')->willReturn(['3', '5']);
        $product->method('getCreatedAt')->willReturn('2026-01-01 00:00:00');
        $product->method('getUpdatedAt')->willReturn('2026-02-01 00:00:00');
        $product->method('getMediaGalleryEntries')->willReturn([$visibleImage, $hiddenImage]);
        $product->method('getData')->willReturnMap([
            ['special_price', null, '9.99'],
            ['description', null, 'Long description'],
            ['short_description', null, 'Short one'],
            ['url_key', null, 'test-product'],
        ]);

        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('get')->with('SKU-1')->willReturn($product);

        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->willReturn('https://example.com/media/');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $tool = new GetProductDetails($productRepository, $storeManager);

        $result = $tool->execute(['sku' => 'SKU-1']);
        $card = $result['product'];

        $this->assertSame('SKU-1', $card['sku']);
        $this->assertSame('enabled', $card['status']);
        $this->assertSame('catalog_search', $card['visibility']);
        $this->assertSame('9.99', $card['special_price']);
        $this->assertSame([3, 5], $card['category_ids']);
        $this->assertCount(1, $card['images']);
        $this->assertSame(
            'https://example.com/media/catalog/product/a/b/visible.jpg',
            $card['images'][0]['url']
        );
        $this->assertSame(['image', 'small_image'], $card['images'][0]['types']);
    }

    /**
     * The customer view: for anonymous callers a disabled product must be reported with
     * the exact same error as an unknown SKU — existence must not be probeable.
     */
    public function testAnonymousCallerSeesDisabledProductAsMissing(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getStatus')->willReturn(Status::STATUS_DISABLED);

        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('get')->willReturn($product);

        $tool = new GetProductDetails($productRepository, $this->createMock(StoreManagerInterface::class));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Product with SKU "SKU-2" does not exist.');

        $tool->execute(['sku' => 'SKU-2']);
    }

    /**
     * A product without a media gallery must yield an empty images list, not an error.
     * Uses the admin view — the fixture product is disabled, which the customer view
     * reports as missing (covered separately above).
     */
    public function testReturnsEmptyImagesWhenGalleryIsNull(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getSku')->willReturn('SKU-2');
        $product->method('getStatus')->willReturn(Status::STATUS_DISABLED);
        $product->method('getVisibility')->willReturn(Visibility::VISIBILITY_NOT_VISIBLE);
        $product->method('getCategoryIds')->willReturn([]);
        $product->method('getMediaGalleryEntries')->willReturn(null);

        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('get')->willReturn($product);

        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->willReturn('https://example.com/media/');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $tool = new GetProductDetails($productRepository, $storeManager);

        $result = $tool->executeWithContext(
            ['sku' => 'SKU-2'],
            new AdminContext(1, ['Magento_Catalog::products'])
        );

        $this->assertSame([], $result['product']['images']);
        $this->assertSame('disabled', $result['product']['status']);
        $this->assertSame('not_visible', $result['product']['visibility']);
    }

    /**
     * Builds a media gallery entry mock.
     *
     * @param string[] $types
     */
    private function mockGalleryEntry(
        bool $disabled,
        string $file,
        string $label,
        int $position,
        array $types
    ): ProductAttributeMediaGalleryEntryInterface {
        $entry = $this->createMock(ProductAttributeMediaGalleryEntryInterface::class);
        $entry->method('isDisabled')->willReturn($disabled);
        $entry->method('getFile')->willReturn($file);
        $entry->method('getLabel')->willReturn($label);
        $entry->method('getPosition')->willReturn($position);
        $entry->method('getTypes')->willReturn($types);

        return $entry;
    }
}
