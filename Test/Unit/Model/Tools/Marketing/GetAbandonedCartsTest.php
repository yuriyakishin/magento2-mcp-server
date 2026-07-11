<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Tools\Marketing;

use Magento\Framework\DataObject;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Quote\Model\ResourceModel\Quote\Collection as QuoteCollection;
use Magento\Quote\Model\ResourceModel\Quote\CollectionFactory as QuoteCollectionFactory;
use Magento\Quote\Model\ResourceModel\Quote\Item\Collection as QuoteItemCollection;
use Magento\Quote\Model\ResourceModel\Quote\Item\CollectionFactory as QuoteItemCollectionFactory;
use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\Tools\Marketing\GetAbandonedCarts;

class GetAbandonedCartsTest extends TestCase
{
    /**
     * Customer contact data — the tool must require the Abandoned Carts report ACL
     * resource.
     */
    public function testRequiresAbandonedCartsAclResource(): void
    {
        $tool = new GetAbandonedCarts(
            $this->createMock(QuoteCollectionFactory::class),
            $this->createMock(QuoteItemCollectionFactory::class),
            $this->createMock(DateTime::class)
        );

        $this->assertSame('Magento_Reports::abandoned', $tool->getRequiredAclResource());
        $this->assertSame('cart_list_abandoned', $tool->getName());
    }

    /**
     * Malformed arguments must fail validation before any collection is built.
     */
    public function testThrowsOnInvalidArguments(): void
    {
        $quoteCollectionFactory = $this->createMock(QuoteCollectionFactory::class);
        $quoteCollectionFactory->expects($this->never())->method('create');

        $tool = new GetAbandonedCarts(
            $quoteCollectionFactory,
            $this->createMock(QuoteItemCollectionFactory::class),
            $this->createMock(DateTime::class)
        );

        $invalid = [
            ['days' => 0],
            ['min_age_hours' => -1],
            ['min_total' => -5],
            ['limit' => 0],
            ['page' => 0],
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
     * The abandonment window is derived from "now": look-back start and minimum idle
     * age both become updated_at bounds, and the page's cart items are attached to
     * their carts.
     */
    public function testListsAbandonedCartsWithItems(): void
    {
        $now = strtotime('2026-07-08 12:00:00');

        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn($now);

        $quote = new DataObject([
            'id' => 77,
            'customer_firstname' => 'Emma',
            'customer_lastname' => 'K.',
            'customer_email' => 'emma@example.com',
            'customer_is_guest' => '0',
            'created_at' => '2026-07-07 15:00:00',
            'updated_at' => '2026-07-07 15:30:00',
            'grand_total' => '1500.00',
            'quote_currency_code' => 'EUR',
        ]);

        $filters = [];
        $collection = $this->createMock(QuoteCollection::class);
        $collection->method('addFieldToFilter')->willReturnCallback(
            function (string $field, $condition) use (&$filters, $collection) {
                $filters[] = [$field, $condition];
                return $collection;
            }
        );
        $collection->expects($this->once())->method('setOrder')->with('updated_at', 'DESC');
        $collection->expects($this->once())->method('setPageSize')->with(20);
        $collection->expects($this->once())->method('setCurPage')->with(1);
        $collection->method('getSize')->willReturn(1);
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$quote]));

        $quoteCollectionFactory = $this->createMock(QuoteCollectionFactory::class);
        $quoteCollectionFactory->method('create')->willReturn($collection);

        $itemCollection = $this->createMock(QuoteItemCollection::class);
        $itemCollection->method('addFieldToFilter')->willReturnSelf();
        $itemCollection->method('getIterator')->willReturn(new \ArrayIterator([
            new DataObject([
                'quote_id' => '77',
                'sku' => 'WS02',
                'name' => 'Gabrielle Micro Sleeve Top',
                'qty' => '2',
                'price' => '750.00',
                'row_total' => '1500.00',
            ]),
        ]));

        $itemCollectionFactory = $this->createMock(QuoteItemCollectionFactory::class);
        $itemCollectionFactory->method('create')->willReturn($itemCollection);

        $tool = new GetAbandonedCarts($quoteCollectionFactory, $itemCollectionFactory, $dateTime);

        $result = $tool->execute(['min_total' => 1000]);

        $this->assertContains(['is_active', 1], $filters);
        $this->assertContains(['items_count', ['gt' => 0]], $filters);
        $this->assertContains(['customer_email', ['notnull' => true]], $filters);
        $this->assertContains(['updated_at', ['gteq' => date('Y-m-d H:i:s', $now - 14 * 86400)]], $filters);
        $this->assertContains(['updated_at', ['lteq' => date('Y-m-d H:i:s', $now - 3600)]], $filters);
        $this->assertContains(['grand_total', ['gteq' => 1000.0]], $filters);

        $this->assertSame(1, $result['count']);
        $this->assertSame(1, $result['total']);
        $cart = $result['carts'][0];
        $this->assertSame('Emma K.', $cart['customer_name']);
        $this->assertSame('emma@example.com', $cart['customer_email']);
        $this->assertFalse($cart['is_guest']);
        $this->assertSame(1500.0, $cart['grand_total']);
        $this->assertSame('WS02', $cart['items'][0]['sku']);
        $this->assertSame(2.0, $cart['items'][0]['qty']);
    }

    /**
     * A page with no matching carts must not query for items at all.
     */
    public function testEmptyResultSkipsItemQuery(): void
    {
        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(time());

        $collection = $this->createMock(QuoteCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getSize')->willReturn(0);
        $collection->method('getIterator')->willReturn(new \ArrayIterator([]));

        $quoteCollectionFactory = $this->createMock(QuoteCollectionFactory::class);
        $quoteCollectionFactory->method('create')->willReturn($collection);

        $itemCollectionFactory = $this->createMock(QuoteItemCollectionFactory::class);
        $itemCollectionFactory->expects($this->never())->method('create');

        $tool = new GetAbandonedCarts($quoteCollectionFactory, $itemCollectionFactory, $dateTime);

        $result = $tool->execute([]);

        $this->assertSame(0, $result['count']);
        $this->assertSame([], $result['carts']);
    }
}
