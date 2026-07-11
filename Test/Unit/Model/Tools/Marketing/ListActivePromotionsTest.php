<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Tools\Marketing;

use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\SalesRule\Model\ResourceModel\Rule\Collection;
use Magento\SalesRule\Model\ResourceModel\Rule\CollectionFactory;
use Magento\SalesRule\Model\Rule;
use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\Tools\Marketing\ListActivePromotions;

class ListActivePromotionsTest extends TestCase
{
    /**
     * promotion_list is a public tool and must not require an ACL resource.
     */
    public function testRequiresNoAclResource(): void
    {
        $tool = new ListActivePromotions(
            $this->createMock(CollectionFactory::class),
            $this->createMock(TimezoneInterface::class)
        );

        $this->assertNull($tool->getRequiredAclResource());
        $this->assertSame('promotion_list', $tool->getName());
    }

    /**
     * A non-positive "limit" argument must fail validation.
     */
    public function testThrowsOnInvalidLimit(): void
    {
        $tool = new ListActivePromotions(
            $this->createMock(CollectionFactory::class),
            $this->createMock(TimezoneInterface::class)
        );

        $this->expectException(\InvalidArgumentException::class);

        $tool->execute(['limit' => 0]);
    }

    /**
     * Active rules are returned with their public fields; the coupon CODE itself must
     * never appear in the result, only the fact that one is required.
     */
    public function testReturnsActivePromotionsWithoutCouponCodes(): void
    {
        $rule = $this->createMock(Rule::class);
        $rule->method('getData')->willReturnMap([
            ['name', null, 'Summer Sale'],
            ['description', null, '10% off everything'],
            ['from_date', null, '2026-07-01'],
            ['to_date', null, '2026-07-31'],
            ['simple_action', null, 'by_percent'],
            ['discount_amount', null, '10.0000'],
            ['coupon_type', null, (string) Rule::COUPON_TYPE_SPECIFIC],
        ]);

        $collection = $this->createMock(Collection::class);
        $collection->expects($this->exactly(3))->method('addFieldToFilter');
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$rule]));

        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $timezone = $this->createMock(TimezoneInterface::class);
        $timezone->method('date')->willReturn(new \DateTime('2026-07-07'));

        $tool = new ListActivePromotions($collectionFactory, $timezone);

        $result = $tool->execute([]);

        $this->assertSame(1, $result['count']);
        $promotion = $result['promotions'][0];
        $this->assertSame('Summer Sale', $promotion['name']);
        $this->assertSame('by_percent', $promotion['action']);
        $this->assertSame(10.0, $promotion['discount_amount']);
        $this->assertTrue($promotion['coupon_required']);
        $this->assertArrayNotHasKey('coupon_code', $promotion);
        $this->assertArrayNotHasKey('code', $promotion);
    }

    /**
     * A no-coupon rule reports coupon_required = false; no rules yields an empty list.
     */
    public function testNoCouponRuleAndEmptyCollection(): void
    {
        $rule = $this->createMock(Rule::class);
        $rule->method('getData')->willReturnMap([
            ['coupon_type', null, (string) Rule::COUPON_TYPE_NO_COUPON],
        ]);

        $collection = $this->createMock(Collection::class);
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$rule]));

        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $timezone = $this->createMock(TimezoneInterface::class);
        $timezone->method('date')->willReturn(new \DateTime('2026-07-07'));

        $tool = new ListActivePromotions($collectionFactory, $timezone);

        $result = $tool->execute([]);

        $this->assertFalse($result['promotions'][0]['coupon_required']);
    }
}
