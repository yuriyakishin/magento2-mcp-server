<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Tools\Product;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\CatalogInventory\Api\Data\StockItemCollectionInterface;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\CatalogInventory\Api\StockItemCriteriaInterface;
use Magento\CatalogInventory\Api\StockItemCriteriaInterfaceFactory;
use Magento\CatalogInventory\Api\StockItemRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\Tools\Product\GetLowStockProducts;
use Yu\McpServer\Model\WriteToolInterface;

class GetLowStockProductsTest extends TestCase
{
    /**
     * The tool is a read-only, ACL-gated tool — not a write tool.
     */
    public function testDeclaresReadOnlyAclGatedContract(): void
    {
        $tool = $this->createTool();

        $this->assertNotInstanceOf(WriteToolInterface::class, $tool);
        $this->assertSame('Magento_Catalog::products', $tool->getRequiredAclResource());
        $this->assertSame('product_low_stock', $tool->getName());
    }

    /**
     * Negative threshold and non-positive limit must be rejected.
     */
    public function testThrowsOnInvalidArguments(): void
    {
        $tool = $this->createTool();

        foreach (
            [
                ['threshold' => -1],
                ['threshold' => 'many'],
                ['limit' => 0],
                ['limit' => 'all'],
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
     * No low-stock items: empty result, and the product collection is never even created.
     */
    public function testReturnsEmptyListWithoutLoadingProducts(): void
    {
        $criteria = $this->createMock(StockItemCriteriaInterface::class);
        $criteria->expects($this->once())->method('setQtyFilter')->with('<', 10.0);
        $criteria->expects($this->once())->method('setLimit')->with(0, 500);

        $productCollectionFactory = $this->createMock(CollectionFactory::class);
        $productCollectionFactory->expects($this->never())->method('create');

        $tool = $this->createTool(
            criteria: $criteria,
            stockItems: [],
            productCollectionFactory: $productCollectionFactory
        );

        $result = $tool->execute([]);

        $this->assertSame(['threshold' => 10.0, 'products' => [], 'count' => 0], $result);
    }

    /**
     * Low-stock items are combined with product data, keeping the repository's qty order.
     * Stock rows whose product was filtered out (composite types) are skipped, and the
     * caller's limit is applied after that filtering.
     */
    public function testReturnsLowStockProducts(): void
    {
        $criteria = $this->createMock(StockItemCriteriaInterface::class);
        $criteria->expects($this->once())->method('setQtyFilter')->with('<', 5.0);
        $criteria->expects($this->once())->method('setLimit')->with(0, 500);

        $stockItems = [
            $this->mockStockItem(99, 0.0, true),
            $this->mockStockItem(11, 0.0, false),
            $this->mockStockItem(22, 3.0, true),
            $this->mockStockItem(33, 4.0, true),
        ];

        $collection = $this->createMock(Collection::class);
        $collection->method('addAttributeToSelect')->willReturnSelf();
        $collection->expects($this->once())->method('addFieldToFilter')
            ->with('entity_id', ['in' => [99, 11, 22, 33]]);
        $collection->expects($this->once())->method('addAttributeToFilter')
            ->with('type_id', ['in' => ['simple', 'virtual']]);
        // Product 99 (a composite parent) is absent: the type filter removed it in the DB.
        $collection->method('getIterator')->willReturn(new \ArrayIterator([
            $this->mockProduct(22, 'SKU-22', 'Almost Out', Status::STATUS_ENABLED),
            $this->mockProduct(11, 'SKU-11', 'Gone', Status::STATUS_DISABLED),
            $this->mockProduct(33, 'SKU-33', 'Beyond Limit', Status::STATUS_ENABLED),
        ]));

        $productCollectionFactory = $this->createMock(CollectionFactory::class);
        $productCollectionFactory->method('create')->willReturn($collection);

        $tool = $this->createTool(
            criteria: $criteria,
            stockItems: $stockItems,
            productCollectionFactory: $productCollectionFactory
        );

        $result = $tool->execute(['threshold' => 5, 'limit' => 2]);

        $this->assertSame(2, $result['count']);
        $this->assertSame(
            ['sku' => 'SKU-11', 'name' => 'Gone', 'qty' => 0.0, 'is_in_stock' => false, 'status' => 'disabled'],
            $result['products'][0]
        );
        $this->assertSame(
            ['sku' => 'SKU-22', 'name' => 'Almost Out', 'qty' => 3.0, 'is_in_stock' => true, 'status' => 'enabled'],
            $result['products'][1]
        );
    }

    /**
     * Builds the tool with sensible default mocks, overridable per test.
     *
     * @param StockItemInterface[] $stockItems
     */
    private function createTool(
        ?StockItemCriteriaInterface $criteria = null,
        array $stockItems = [],
        ?CollectionFactory $productCollectionFactory = null
    ): GetLowStockProducts {
        $criteria ??= $this->createMock(StockItemCriteriaInterface::class);
        $criteriaFactory = $this->createMock(StockItemCriteriaInterfaceFactory::class);
        $criteriaFactory->method('create')->willReturn($criteria);

        $stockItemCollection = $this->createMock(StockItemCollectionInterface::class);
        $stockItemCollection->method('getItems')->willReturn($stockItems);
        $stockItemRepository = $this->createMock(StockItemRepositoryInterface::class);
        $stockItemRepository->method('getList')->willReturn($stockItemCollection);

        $stockConfiguration = $this->createMock(StockConfigurationInterface::class);
        $stockConfiguration->method('getManageStock')->willReturn(1);
        $stockConfiguration->method('getIsQtyTypeIds')->willReturn(
            ['simple' => true, 'virtual' => true, 'bundle' => false, 'configurable' => false]
        );

        return new GetLowStockProducts(
            $criteriaFactory,
            $stockItemRepository,
            $stockConfiguration,
            $productCollectionFactory ?? $this->createMock(CollectionFactory::class)
        );
    }

    /**
     * Builds a stock item mock.
     */
    private function mockStockItem(int $productId, float $qty, bool $isInStock): StockItemInterface
    {
        $stockItem = $this->createMock(StockItemInterface::class);
        $stockItem->method('getProductId')->willReturn($productId);
        $stockItem->method('getQty')->willReturn($qty);
        $stockItem->method('getIsInStock')->willReturn($isInStock);

        return $stockItem;
    }

    /**
     * Builds a product mock with the fields the tool reads.
     */
    private function mockProduct(int $id, string $sku, string $name, int $status): Product
    {
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn($id);
        $product->method('getSku')->willReturn($sku);
        $product->method('getName')->willReturn($name);
        $product->method('getStatus')->willReturn($status);

        return $product;
    }
}
