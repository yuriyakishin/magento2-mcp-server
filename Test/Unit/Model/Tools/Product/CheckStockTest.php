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
use Yu\McpServer\Model\Auth\AdminContext;
use Yu\McpServer\Model\Tools\Product\CheckStock;

class CheckStockTest extends TestCase
{
    /**
     * product_check_stock is a public tool and must not require an ACL resource.
     */
    public function testRequiresNoAclResource(): void
    {
        $tool = new CheckStock(
            $this->createMock(StockRegistryInterface::class),
            $this->createMock(ProductRepositoryInterface::class)
        );

        $this->assertNull($tool->getRequiredAclResource());
        $this->assertSame('product_check_stock', $tool->getName());
    }

    /**
     * A missing, empty or oversized "skus" argument must fail validation.
     */
    public function testThrowsOnInvalidArguments(): void
    {
        $tool = new CheckStock(
            $this->createMock(StockRegistryInterface::class),
            $this->createMock(ProductRepositoryInterface::class)
        );

        foreach (
            [
                [],
                ['skus' => []],
                ['skus' => ['ok', '']],
                ['skus' => 'A-1'],
                ['skus' => array_map(static fn ($i) => "sku-$i", range(1, 51))],
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
     * Known SKUs report qty/is_in_stock; unknown SKUs are reported per item, without
     * failing the whole call.
     */
    public function testMixesFoundAndNotFoundResults(): void
    {
        $stockItem = $this->createMock(StockItemInterface::class);
        $stockItem->method('getQty')->willReturn(7.0);
        $stockItem->method('getIsInStock')->willReturn(true);

        $stockRegistry = $this->createMock(StockRegistryInterface::class);
        $stockRegistry->method('getStockItemBySku')->willReturn($stockItem);

        $tool = new CheckStock($stockRegistry, $this->productRepositoryMock(['known' => Status::STATUS_ENABLED]));

        $result = $tool->execute(['skus' => ['known', 'missing']]);

        $this->assertSame(2, $result['count']);
        $this->assertSame(
            ['sku' => 'known', 'found' => true, 'qty' => 7.0, 'is_in_stock' => true],
            $result['stock'][0]
        );
        $this->assertSame(['sku' => 'missing', 'found' => false], $result['stock'][1]);
    }

    /**
     * Duplicate SKUs are checked once.
     */
    public function testDeduplicatesSkus(): void
    {
        $stockItem = $this->createMock(StockItemInterface::class);
        $stockItem->method('getQty')->willReturn(1.0);
        $stockItem->method('getIsInStock')->willReturn(true);

        $stockRegistry = $this->createMock(StockRegistryInterface::class);
        $stockRegistry->expects($this->once())->method('getStockItemBySku')->willReturn($stockItem);

        $tool = new CheckStock($stockRegistry, $this->productRepositoryMock(['A-1' => Status::STATUS_ENABLED]));

        $result = $tool->execute(['skus' => ['A-1', 'A-1', ' A-1 ']]);

        $this->assertSame(1, $result['count']);
    }

    /**
     * The customer view: a disabled product's stock must be reported exactly like an
     * unknown SKU for anonymous callers — existence must not be probeable.
     */
    public function testAnonymousCallerSeesDisabledProductAsNotFound(): void
    {
        $stockRegistry = $this->createMock(StockRegistryInterface::class);
        $stockRegistry->expects($this->never())->method('getStockItemBySku');

        $tool = new CheckStock($stockRegistry, $this->productRepositoryMock(['hidden' => Status::STATUS_DISABLED]));

        $result = $tool->execute(['skus' => ['hidden']]);

        $this->assertSame(['sku' => 'hidden', 'found' => false], $result['stock'][0]);
    }

    /**
     * The admin view: with the catalog ACL resource, a disabled product's stock is
     * visible and its status is reported alongside.
     */
    public function testAdminWithCatalogAclSeesDisabledProductWithStatus(): void
    {
        $stockItem = $this->createMock(StockItemInterface::class);
        $stockItem->method('getQty')->willReturn(0.0);
        $stockItem->method('getIsInStock')->willReturn(false);

        $stockRegistry = $this->createMock(StockRegistryInterface::class);
        $stockRegistry->method('getStockItemBySku')->willReturn($stockItem);

        $tool = new CheckStock($stockRegistry, $this->productRepositoryMock(['hidden' => Status::STATUS_DISABLED]));

        $result = $tool->executeWithContext(
            ['skus' => ['hidden']],
            new AdminContext(1, ['Magento_Catalog::products'])
        );

        $this->assertSame(
            ['sku' => 'hidden', 'found' => true, 'qty' => 0.0, 'is_in_stock' => false, 'status' => 'disabled'],
            $result['stock'][0]
        );
    }

    /**
     * An authenticated admin WITHOUT the catalog ACL resource gets the customer view.
     */
    public function testAdminWithoutCatalogAclGetsCustomerView(): void
    {
        $tool = new CheckStock(
            $this->createMock(StockRegistryInterface::class),
            $this->productRepositoryMock(['hidden' => Status::STATUS_DISABLED])
        );

        $result = $tool->executeWithContext(
            ['skus' => ['hidden']],
            new AdminContext(1, ['Magento_Sales::sales'])
        );

        $this->assertSame(['sku' => 'hidden', 'found' => false], $result['stock'][0]);
    }

    /**
     * Builds a product repository whose get() returns a product with the mapped status,
     * or throws NoSuchEntityException for unmapped SKUs.
     *
     * @param array<string, int> $statusBySku
     */
    private function productRepositoryMock(array $statusBySku): ProductRepositoryInterface
    {
        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->method('get')->willReturnCallback(
            function (string $sku) use ($statusBySku) {
                if (!isset($statusBySku[$sku])) {
                    throw NoSuchEntityException::singleField('sku', $sku);
                }
                $product = $this->createMock(Product::class);
                $product->method('getStatus')->willReturn($statusBySku[$sku]);

                return $product;
            }
        );

        return $repository;
    }
}
