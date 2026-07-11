<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Tools\Sales;

use Magento\Framework\DataObject;
use Magento\Sales\Model\ResourceModel\Order\Collection as OrderCollection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\Tools\Sales\CompareSalesPeriods;

class CompareSalesPeriodsTest extends TestCase
{
    /**
     * Aggregated revenue is order data — the tool must require the Sales ACL resource.
     */
    public function testRequiresSalesAclResource(): void
    {
        $tool = new CompareSalesPeriods($this->createMock(OrderCollectionFactory::class));

        $this->assertSame('Magento_Sales::sales', $tool->getRequiredAclResource());
        $this->assertSame('sales_compare_periods', $tool->getName());
    }

    /**
     * Three period arguments are mandatory; malformed dates must fail validation before
     * any collection is built.
     */
    public function testThrowsOnInvalidArguments(): void
    {
        $factory = $this->createMock(OrderCollectionFactory::class);
        $factory->expects($this->never())->method('create');

        $tool = new CompareSalesPeriods($factory);

        $invalid = [
            [],
            ['period_a_from' => '2026-05-01', 'period_a_to' => '2026-05-31'],
            ['period_a_from' => '2026-05-01', 'period_a_to' => 'May 31', 'period_b_from' => '2026-06-01'],
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
     * Both periods are summarized with sales_summary rules (canceled excluded from
     * revenue) and the deltas are computed server-side.
     */
    public function testComparesPeriodsAndComputesDeltas(): void
    {
        $periodA = [
            new DataObject(['state' => 'complete', 'order_currency_code' => 'EUR', 'grand_total' => '1000.00']),
            new DataObject(['state' => 'canceled', 'order_currency_code' => 'EUR', 'grand_total' => '900.00']),
        ];
        $periodB = [
            new DataObject(['state' => 'complete', 'order_currency_code' => 'EUR', 'grand_total' => '1500.00']),
            new DataObject(['state' => 'processing', 'order_currency_code' => 'EUR', 'grand_total' => '500.00']),
        ];

        $factory = $this->createMock(OrderCollectionFactory::class);
        $factory->method('create')->willReturnOnConsecutiveCalls(
            $this->collectionMock($periodA),
            $this->collectionMock($periodB)
        );

        $result = (new CompareSalesPeriods($factory))->execute([
            'period_a_from' => '2026-05-01',
            'period_a_to' => '2026-05-31',
            'period_b_from' => '2026-06-01',
            'period_b_to' => '2026-06-30',
        ]);

        $this->assertSame(2, $result['period_a']['orders_total']);
        $this->assertSame(1000.0, $result['period_a']['by_currency'][0]['revenue']);
        $this->assertSame(2000.0, $result['period_b']['by_currency'][0]['revenue']);
        // The date-only period_a_to must be expanded to the end of the day.
        $this->assertSame('2026-05-31 23:59:59', $result['period_a']['to']);

        $this->assertSame(0.0, $result['change_a_to_b']['orders_total_pct']);
        $this->assertSame(100.0, $result['change_a_to_b']['by_currency'][0]['revenue_pct']);
        $this->assertSame(100.0, $result['change_a_to_b']['by_currency'][0]['orders_pct']);
    }

    /**
     * A zero-base period yields null percentages instead of a division error.
     */
    public function testNullPercentagesOnZeroBase(): void
    {
        $factory = $this->createMock(OrderCollectionFactory::class);
        $factory->method('create')->willReturnOnConsecutiveCalls(
            $this->collectionMock([]),
            $this->collectionMock([
                new DataObject(['state' => 'complete', 'order_currency_code' => 'EUR', 'grand_total' => '100.00']),
            ])
        );

        $result = (new CompareSalesPeriods($factory))->execute([
            'period_a_from' => '2026-05-01',
            'period_a_to' => '2026-05-31',
            'period_b_from' => '2026-06-01',
        ]);

        $this->assertNull($result['change_a_to_b']['orders_total_pct']);
        $this->assertNull($result['change_a_to_b']['by_currency'][0]['revenue_pct']);
    }

    /**
     * Builds an order collection mock iterating the given rows.
     *
     * @param DataObject[] $orders
     */
    private function collectionMock(array $orders): OrderCollection
    {
        $collection = $this->createMock(OrderCollection::class);
        $collection->method('getIterator')->willReturn(new \ArrayIterator($orders));

        return $collection;
    }
}
