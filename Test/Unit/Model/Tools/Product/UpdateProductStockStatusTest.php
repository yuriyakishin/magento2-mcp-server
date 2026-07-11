<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Tools\Product;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\Tools\Product\UpdateProductStockStatus;
use Yu\McpServer\Model\WriteToolInterface;

class UpdateProductStockStatusTest extends TestCase
{
    /**
     * The tool must be a write tool gated by the documented Products ACL resource.
     */
    public function testDeclaresWriteToolContract(): void
    {
        $tool = new UpdateProductStockStatus(
            $this->createMock(ProductRepositoryInterface::class),
            $this->createMock(StockRegistryInterface::class)
        );

        $this->assertInstanceOf(WriteToolInterface::class, $tool);
        $this->assertSame('Magento_Catalog::products', $tool->getRequiredAclResource());
        $this->assertSame('product_update_stock', $tool->getName());
    }

    /**
     * Missing/empty skus and a call with neither status nor qty must be rejected.
     */
    public function testThrowsOnInvalidArguments(): void
    {
        $tool = new UpdateProductStockStatus(
            $this->createMock(ProductRepositoryInterface::class),
            $this->createMock(StockRegistryInterface::class)
        );

        foreach (
            [
                [],
                ['skus' => []],
                ['skus' => ['A-1']],
                ['skus' => ['A-1'], 'status' => 'archived'],
                ['skus' => ['A-1'], 'qty' => -1],
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
     * One unknown SKU must abort the whole batch before any product is saved.
     */
    public function testUnknownSkuAbortsBatchWithoutChanges(): void
    {
        $known = $this->createMock(Product::class);

        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('get')->willReturnCallback(
            static function (string $sku) use ($known) {
                if ($sku === 'known') {
                    return $known;
                }
                throw NoSuchEntityException::singleField('sku', $sku);
            }
        );
        $productRepository->expects($this->never())->method('save');

        $stockRegistry = $this->createMock(StockRegistryInterface::class);
        $stockRegistry->expects($this->never())->method('updateStockItemBySku');

        $tool = new UpdateProductStockStatus($productRepository, $stockRegistry);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No products were updated.');

        $tool->execute(['skus' => ['known', 'missing'], 'status' => 'enabled']);
    }

    /**
     * A status-only update must save the product and never touch the stock registry.
     */
    public function testStatusOnlyUpdateDoesNotTouchStock(): void
    {
        $product = $this->createMock(Product::class);
        $product->expects($this->once())->method('setStatus')->with(Status::STATUS_ENABLED);
        $product->method('getStatus')->willReturn(Status::STATUS_ENABLED);

        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        // Store id 0 is part of the contract: loading in a storefront scope would turn the
        // subsequent save into a store-level override instead of a global status change.
        $productRepository->method('get')->with('A-1', false, 0)->willReturn($product);
        $productRepository->expects($this->once())->method('save')->with($product)->willReturn($product);

        $stockRegistry = $this->createMock(StockRegistryInterface::class);
        $stockRegistry->expects($this->never())->method('getStockItemBySku');

        $tool = new UpdateProductStockStatus($productRepository, $stockRegistry);

        $result = $tool->execute(['skus' => ['A-1'], 'status' => 'enabled']);

        $this->assertSame(1, $result['count']);
        $this->assertSame('enabled', $result['updated'][0]['status']);
    }

    /**
     * A qty-only update must go through the stock registry and never re-save the product.
     */
    public function testQtyOnlyUpdateDoesNotSaveProduct(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getStatus')->willReturn(Status::STATUS_DISABLED);

        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('get')->willReturn($product);
        $productRepository->expects($this->never())->method('save');

        $stockItem = $this->createMock(StockItemInterface::class);
        $stockItem->expects($this->once())->method('setQty')->with(10.0);
        $stockItem->expects($this->once())->method('setIsInStock')->with(true);

        $stockRegistry = $this->createMock(StockRegistryInterface::class);
        $stockRegistry->method('getStockItemBySku')->with('A-1')->willReturn($stockItem);
        $stockRegistry->expects($this->once())->method('updateStockItemBySku')->with('A-1', $stockItem);

        $tool = new UpdateProductStockStatus($productRepository, $stockRegistry);

        $result = $tool->execute(['skus' => ['A-1'], 'qty' => 10]);

        $this->assertSame(10.0, $result['updated'][0]['qty']);
        $this->assertSame('disabled', $result['updated'][0]['status']);
    }

    /**
     * qty = 0 must mark the product as out of stock.
     */
    public function testZeroQtyMarksOutOfStock(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getStatus')->willReturn(Status::STATUS_ENABLED);

        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('get')->willReturn($product);

        $stockItem = $this->createMock(StockItemInterface::class);
        $stockItem->expects($this->once())->method('setQty')->with(0.0);
        $stockItem->expects($this->once())->method('setIsInStock')->with(false);

        $stockRegistry = $this->createMock(StockRegistryInterface::class);
        $stockRegistry->method('getStockItemBySku')->willReturn($stockItem);

        $tool = new UpdateProductStockStatus($productRepository, $stockRegistry);

        $tool->execute(['skus' => ['A-1'], 'qty' => 0]);
    }
}
