<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Tools\Product;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\Tools\Product\UpdateProduct;
use Yu\McpServer\Model\WriteToolInterface;

class UpdateProductTest extends TestCase
{
    /**
     * The tool must be a write tool gated by the documented Products ACL resource.
     */
    public function testDeclaresWriteToolContract(): void
    {
        $tool = new UpdateProduct($this->createMock(ProductRepositoryInterface::class));

        $this->assertInstanceOf(WriteToolInterface::class, $tool);
        $this->assertSame('Magento_Catalog::products', $tool->getRequiredAclResource());
        $this->assertSame('product_update', $tool->getName());
    }

    /**
     * Missing sku, empty field set and malformed field values must all fail validation
     * before the repository is touched.
     */
    public function testThrowsOnInvalidArguments(): void
    {
        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->expects($this->never())->method('get');
        $productRepository->expects($this->never())->method('save');

        $tool = new UpdateProduct($productRepository);

        $invalid = [
            [],
            ['sku' => ''],
            ['sku' => 'A-1'],
            ['sku' => 'A-1', 'name' => ''],
            ['sku' => 'A-1', 'price' => 0],
            ['sku' => 'A-1', 'price' => 'free'],
            ['sku' => 'A-1', 'special_price' => -5],
            ['sku' => 'A-1', 'status' => 'archived'],
            ['sku' => 'A-1', 'remove_special_price' => 'yes'],
            ['sku' => 'A-1', 'special_price' => 5, 'remove_special_price' => true],
        ];
        foreach ($invalid as $arguments) {
            try {
                $tool->execute($arguments);
                $this->fail('Expected InvalidArgumentException for: ' . json_encode($arguments));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * An unknown SKU must surface as a runtime error without any save.
     */
    public function testUnknownSkuFailsWithoutSave(): void
    {
        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('get')->willThrowException(NoSuchEntityException::singleField('sku', 'missing'));
        $productRepository->expects($this->never())->method('save');

        $tool = new UpdateProduct($productRepository);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        $tool->execute(['sku' => 'missing', 'price' => 10]);
    }

    /**
     * A special price at or above the (new) regular price must be rejected before saving.
     */
    public function testSpecialPriceMustBeBelowRegularPrice(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getPrice')->willReturn(100.0);

        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('get')->willReturn($product);
        $productRepository->expects($this->never())->method('save');

        $tool = new UpdateProduct($productRepository);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('below the regular price');

        $tool->execute(['sku' => 'A-1', 'special_price' => 120]);
    }

    /**
     * Provided fields are applied and saved once, and every change reports its old and
     * new value. Store id 0 is part of the contract (global scope, not a store override).
     */
    public function testUpdatesFieldsAndReportsChanges(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getName')->willReturn('Old Name');
        $product->method('getPrice')->willReturn(100.0);
        $product->method('getStatus')->willReturn(Status::STATUS_DISABLED);
        $product->expects($this->once())->method('setName')->with('New Name');
        $product->expects($this->once())->method('setPrice')->with(80.0);
        $product->expects($this->once())->method('setStatus')->with(Status::STATUS_ENABLED);

        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('get')->with('A-1', false, 0)->willReturn($product);
        $productRepository->expects($this->once())->method('save')->with($product)->willReturn($product);

        $tool = new UpdateProduct($productRepository);

        $result = $tool->execute([
            'sku' => 'A-1',
            'name' => 'New Name',
            'price' => 80,
            'status' => 'enabled',
        ]);

        $this->assertSame('A-1', $result['sku']);
        $this->assertSame(['from' => 'Old Name', 'to' => 'New Name'], $result['changes']['name']);
        $this->assertSame(['from' => 100.0, 'to' => 80.0], $result['changes']['price']);
        $this->assertSame(['from' => 'disabled', 'to' => 'enabled'], $result['changes']['status']);
    }

    /**
     * remove_special_price must clear the attribute and report the change as
     * special_price: old value -> null.
     */
    public function testRemoveSpecialPriceClearsAttribute(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getData')->with('special_price')->willReturn('49.99');
        $product->expects($this->once())->method('setData')->with('special_price', null);

        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('get')->willReturn($product);
        $productRepository->expects($this->once())->method('save')->willReturn($product);

        $tool = new UpdateProduct($productRepository);

        $result = $tool->execute(['sku' => 'A-1', 'remove_special_price' => true]);

        $this->assertSame(['from' => 49.99, 'to' => null], $result['changes']['special_price']);
    }
}
