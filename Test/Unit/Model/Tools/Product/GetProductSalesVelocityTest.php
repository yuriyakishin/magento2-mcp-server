<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Tools\Product;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Sales\Model\ResourceModel\Order\Item\Collection as OrderItemCollection;
use Magento\Sales\Model\ResourceModel\Order\Item\CollectionFactory as OrderItemCollectionFactory;
use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\Tools\Product\GetProductSalesVelocity;

class GetProductSalesVelocityTest extends TestCase
{
    /**
     * Per-product sales volumes are order data — the tool must require the Sales ACL
     * resource.
     */
    public function testRequiresSalesAclResource(): void
    {
        $tool = $this->makeTool([]);

        $this->assertSame('Magento_Sales::sales', $tool->getRequiredAclResource());
        $this->assertSame('product_sales_velocity', $tool->getName());
    }

    /**
     * A missing sku and a non-positive window must fail validation.
     */
    public function testThrowsOnInvalidArguments(): void
    {
        $tool = $this->makeTool([]);

        foreach ([[], ['sku' => ''], ['sku' => 'A-1', 'days' => 0]] as $arguments) {
            try {
                $tool->execute($arguments);
                $this->fail('Expected InvalidArgumentException for: ' . json_encode($arguments));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * An unknown SKU must surface as a runtime error.
     */
    public function testThrowsOnUnknownSku(): void
    {
        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('get')->willThrowException(NoSuchEntityException::singleField('sku', 'nope'));

        $tool = $this->makeTool([], productRepository: $productRepository);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        $tool->execute(['sku' => 'nope']);
    }

    /**
     * Units are summed net of cancellations, orders are counted distinct, and the
     * stockout forecast follows stock_qty / units_per_day.
     */
    public function testComputesVelocityAndStockoutForecast(): void
    {
        // 30-day window: 12 + 6 - 3 canceled = 15 net units → 0.5/day; 30 in stock → 60 days.
        $items = [
            new DataObject([
                'order_id' => '101', 'qty_ordered' => '12', 'qty_canceled' => '0', 'row_total' => '600.00',
            ]),
            new DataObject([
                'order_id' => '102', 'qty_ordered' => '6', 'qty_canceled' => '3', 'row_total' => '150.00',
            ]),
            new DataObject([
                'order_id' => '103', 'qty_ordered' => '2', 'qty_canceled' => '2', 'row_total' => '100.00',
            ]),
        ];

        $tool = $this->makeTool($items, stockQty: 30.0);

        $result = $tool->execute(['sku' => 'WS02', 'days' => 30]);

        $this->assertSame(15.0, $result['units_sold']);
        $this->assertSame(2, $result['orders'], 'fully canceled order 103 must not be counted');
        $this->assertSame(750.0, $result['revenue']);
        $this->assertSame(0.5, $result['units_per_day']);
        $this->assertSame(30.0, $result['stock_qty']);
        $this->assertSame(60, $result['estimated_days_left']);
        $this->assertSame(
            date('Y-m-d', strtotime('2026-07-08 12:00:00') + 60 * 86400),
            $result['estimated_stockout_date']
        );
    }

    /**
     * A product with no sales in the window must report zero velocity and no forecast.
     */
    public function testNoSalesMeansNoForecast(): void
    {
        $tool = $this->makeTool([], stockQty: 10.0);

        $result = $tool->execute(['sku' => 'WS02']);

        $this->assertSame(0.0, $result['units_sold']);
        $this->assertSame(0.0, $result['units_per_day']);
        $this->assertNull($result['estimated_days_left']);
        $this->assertNull($result['estimated_stockout_date']);
    }

    /**
     * A product without a stock row of its own (composite types) reports stock as
     * unknown and still returns the velocity numbers.
     */
    public function testMissingStockRowReportsUnknownStock(): void
    {
        $stockRegistry = $this->createMock(StockRegistryInterface::class);
        $stockRegistry->method('getStockItemBySku')
            ->willThrowException(NoSuchEntityException::singleField('sku', 'CONF-1'));

        $items = [
            new DataObject(['order_id' => '1', 'qty_ordered' => '3', 'qty_canceled' => '0', 'row_total' => '90']),
        ];

        $tool = $this->makeTool($items, stockRegistry: $stockRegistry);

        $result = $tool->execute(['sku' => 'CONF-1', 'days' => 30]);

        $this->assertSame(3.0, $result['units_sold']);
        $this->assertNull($result['stock_qty']);
        $this->assertNull($result['estimated_days_left']);
    }

    /**
     * Builds the tool over the given order item rows, a fixed "now" and a stock quantity.
     *
     * @param DataObject[] $items
     */
    private function makeTool(
        array $items,
        ?float $stockQty = null,
        ?ProductRepositoryInterface $productRepository = null,
        ?StockRegistryInterface $stockRegistry = null
    ): GetProductSalesVelocity {
        $collection = $this->createMock(OrderItemCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($items));

        $collectionFactory = $this->createMock(OrderItemCollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        if ($productRepository === null) {
            $product = $this->createMock(Product::class);
            $product->method('getName')->willReturn('Test Product');
            $productRepository = $this->createMock(ProductRepositoryInterface::class);
            $productRepository->method('get')->willReturn($product);
        }

        if ($stockRegistry === null) {
            $stockItem = $this->createMock(StockItemInterface::class);
            $stockItem->method('getQty')->willReturn($stockQty ?? 0.0);
            $stockItem->method('getIsInStock')->willReturn(($stockQty ?? 0.0) > 0);
            $stockRegistry = $this->createMock(StockRegistryInterface::class);
            $stockRegistry->method('getStockItemBySku')->willReturn($stockItem);
        }

        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(strtotime('2026-07-08 12:00:00'));

        return new GetProductSalesVelocity($collectionFactory, $productRepository, $stockRegistry, $dateTime);
    }
}
