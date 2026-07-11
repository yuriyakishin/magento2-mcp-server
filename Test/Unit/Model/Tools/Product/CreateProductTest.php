<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Tools\Product;

use Magento\Catalog\Api\CategoryLinkManagementInterface;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\ProductFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\Tools\Product\CreateProduct;
use Yu\McpServer\Model\WriteToolInterface;

class CreateProductTest extends TestCase
{
    /**
     * The tool must be a write tool gated by the documented Products ACL resource.
     */
    public function testDeclaresWriteToolContract(): void
    {
        $tool = $this->makeTool();

        $this->assertInstanceOf(WriteToolInterface::class, $tool);
        $this->assertSame('Magento_Catalog::products', $tool->getRequiredAclResource());
        $this->assertSame('product_create', $tool->getName());
    }

    /**
     * Each required argument must be validated before any repository call.
     */
    public function testThrowsWhenRequiredArgumentsMissing(): void
    {
        $tool = $this->makeTool();

        foreach (
            [
                [],
                ['sku' => 'X-1'],
                ['sku' => 'X-1', 'name' => 'Widget'],
                ['sku' => 'X-1', 'name' => 'Widget', 'price' => -5],
            ] as $arguments
        ) {
            try {
                $tool->execute($arguments);
                $this->fail('Expected InvalidArgumentException for arguments: ' . json_encode($arguments));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * An unknown "status" value must be rejected.
     */
    public function testThrowsOnInvalidStatus(): void
    {
        $tool = $this->makeTool();

        $this->expectException(\InvalidArgumentException::class);

        $tool->execute(['sku' => 'X-1', 'name' => 'Widget', 'price' => 10, 'status' => 'archived']);
    }

    /**
     * An already-used SKU must fail hard — creation never updates an existing product.
     */
    public function testThrowsWhenSkuAlreadyExists(): void
    {
        $existing = $this->mockProduct();
        $existing->method('getId')->willReturn(5);

        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('get')->willReturn($existing);
        $productRepository->expects($this->never())->method('save');

        $tool = $this->makeTool(productRepository: $productRepository);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already exists');

        $tool->execute(['sku' => 'X-1', 'name' => 'Widget', 'price' => 10]);
    }

    /**
     * Valid arguments must create a disabled-by-default simple product with seeded stock.
     */
    public function testCreatesDisabledProductByDefault(): void
    {
        $product = $this->mockProduct();
        $product->method('getDefaultAttributeSetId')->willReturn(4);
        $product->expects($this->once())->method('setStatus')->with(Status::STATUS_DISABLED);
        $product->expects($this->once())->method('setStockData')->with([
            'qty' => 3.0,
            'is_in_stock' => 1,
        ]);

        $productFactory = $this->createMock(ProductFactory::class);
        $productFactory->method('create')->willReturn($product);

        $saved = $this->mockProduct();
        $saved->method('getId')->willReturn(101);
        $saved->method('getSku')->willReturn('X-1');
        $saved->method('getName')->willReturn('Widget');
        $saved->method('getPrice')->willReturn(10.5);
        $saved->method('getStatus')->willReturn(Status::STATUS_DISABLED);

        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('get')->willThrowException(
            NoSuchEntityException::singleField('sku', 'X-1')
        );
        $productRepository->expects($this->once())->method('save')->with($product)->willReturn($saved);

        $categoryLinkManagement = $this->createMock(CategoryLinkManagementInterface::class);
        $categoryLinkManagement->expects($this->never())->method('assignProductToCategories');

        $tool = $this->makeTool(
            productFactory: $productFactory,
            productRepository: $productRepository,
            categoryLinkManagement: $categoryLinkManagement
        );

        $result = $tool->execute(['sku' => 'X-1', 'name' => 'Widget', 'price' => 10.5, 'qty' => 3]);

        $this->assertSame(101, $result['product']['id']);
        $this->assertSame('disabled', $result['product']['status']);
        $this->assertSame(3.0, $result['product']['qty']);
        $this->assertSame([], $result['product']['category_ids']);
    }

    /**
     * A nonexistent category ID must fail before the product is created at all.
     */
    public function testThrowsWhenCategoryDoesNotExist(): void
    {
        $categoryRepository = $this->createMock(CategoryRepositoryInterface::class);
        $categoryRepository->method('get')->willThrowException(
            NoSuchEntityException::singleField('id', 777)
        );

        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('get')->willThrowException(
            NoSuchEntityException::singleField('sku', 'X-1')
        );
        $productRepository->expects($this->never())->method('save');

        $tool = $this->makeTool(
            productRepository: $productRepository,
            categoryRepository: $categoryRepository
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Category with ID 777 does not exist.');

        $tool->execute(['sku' => 'X-1', 'name' => 'Widget', 'price' => 10, 'category_ids' => [777]]);
    }

    /**
     * Builds the tool with sensible default mocks, overridable per test.
     */
    private function makeTool(
        ?ProductFactory $productFactory = null,
        ?ProductRepositoryInterface $productRepository = null,
        ?CategoryRepositoryInterface $categoryRepository = null,
        ?CategoryLinkManagementInterface $categoryLinkManagement = null
    ): CreateProduct {
        if ($productRepository === null) {
            $productRepository = $this->createMock(ProductRepositoryInterface::class);
            $productRepository->method('get')->willThrowException(
                NoSuchEntityException::singleField('sku', 'any')
            );
        }

        if ($productFactory === null) {
            $productFactory = $this->createMock(ProductFactory::class);
            $productFactory->method('create')->willReturn($this->mockProduct());
        }

        $store = $this->createMock(Store::class);
        $store->method('getWebsiteId')->willReturn(1);

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return new CreateProduct(
            $productFactory,
            $productRepository,
            $categoryRepository ?? $this->createMock(CategoryRepositoryInterface::class),
            $categoryLinkManagement ?? $this->createMock(CategoryLinkManagementInterface::class),
            $storeManager
        );
    }

    /**
     * All the setters this tool uses (including setStockData()) are declared methods on
     * the Product model, so a plain reflection-based mock is enough.
     */
    private function mockProduct(): Product&MockObject
    {
        return $this->createMock(Product::class);
    }
}
