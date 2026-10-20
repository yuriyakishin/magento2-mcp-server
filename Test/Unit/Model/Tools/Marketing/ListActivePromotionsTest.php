<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Tools\Marketing;

use Magento\Customer\Api\Data\GroupInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\SalesRule\Model\Coupon;
use Magento\SalesRule\Model\ResourceModel\Rule\Collection;
use Magento\SalesRule\Model\ResourceModel\Rule\CollectionFactory;
use Magento\SalesRule\Model\Rule;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\Auth\AdminContext;
use Yu\McpServer\Model\Tools\Marketing\ListActivePromotions;
use Yu\McpServer\Test\Unit\Model\Tools\Catalog\StorefrontScopeMockTrait;

class ListActivePromotionsTest extends TestCase
{
    use StorefrontScopeMockTrait;

    /**
     * promotion_list is a public tool and must not require an ACL resource.
     */
    public function testRequiresNoAclResource(): void
    {
        $tool = $this->tool($this->createMock(CollectionFactory::class));

        $this->assertNull($tool->getRequiredAclResource());
        $this->assertSame('promotion_list', $tool->getName());
    }

    /**
     * A non-positive "limit" argument must fail validation.
     */
    public function testThrowsOnInvalidLimit(): void
    {
        $tool = $this->tool($this->createMock(CollectionFactory::class));

        $this->expectException(\InvalidArgumentException::class);

        $tool->execute(['limit' => 0]);
    }

    /**
     * The customer view: only rules a guest of this website gets (website + NOT LOGGED
     * IN group + date filter), described by their storefront label. The internal rule
     * name and description must not be returned, and neither may a coupon code.
     */
    public function testAnonymousGetsGuestRulesWithStorefrontLabelOnly(): void
    {
        $rule = $this->rule([
            ['name', null, 'Partner deal - wholesale'],
            ['description', null, 'Internal note'],
            ['from_date', null, '2026-07-01'],
            ['to_date', null, '2026-07-31'],
            ['simple_action', null, 'by_percent'],
            ['discount_amount', null, '10.0000'],
            ['coupon_type', null, (string)Rule::COUPON_TYPE_SPECIFIC],
        ], 'Summer Sale');

        $collection = $this->collection([$rule]);
        $collection->expects($this->once())
            ->method('addWebsiteGroupDateFilter')
            ->with(1, GroupInterface::NOT_LOGGED_IN_ID, '2026-07-07');
        $collection->expects($this->never())->method('addFieldToFilter');

        $result = $this->tool($this->factory($collection))->execute([]);

        $this->assertSame(1, $result['count']);
        $this->assertSame(
            [
                'label' => 'Summer Sale',
                'from_date' => '2026-07-01',
                'to_date' => '2026-07-31',
                'action' => 'by_percent',
                'discount_amount' => 10.0,
                'coupon_required' => true,
            ],
            $result['promotions'][0]
        );
    }

    /**
     * The admin view: every active rule of every website and group, with the internal
     * name and description alongside the label.
     */
    public function testAdminGetsAllActiveRulesWithInternalTexts(): void
    {
        $rule = $this->rule([
            ['name', null, 'Partner deal - wholesale'],
            ['description', null, 'Internal note'],
            ['coupon_type', null, (string)Rule::COUPON_TYPE_NO_COUPON],
        ], false);

        $collection = $this->collection([$rule]);
        $collection->expects($this->never())->method('addWebsiteGroupDateFilter');
        $collection->expects($this->exactly(3))->method('addFieldToFilter');

        $result = $this->tool($this->factory($collection))->executeWithContext(
            [],
            new AdminContext(1, ['Magento_SalesRule::quote'])
        );

        $promotion = $result['promotions'][0];
        $this->assertNull($promotion['label']);
        $this->assertSame('Partner deal - wholesale', $promotion['name']);
        $this->assertSame('Internal note', $promotion['description']);
        $this->assertFalse($promotion['coupon_required']);
    }

    /**
     * An admin without the cart price rule ACL gets the customer view.
     */
    public function testAdminWithoutRuleAclGetsCustomerView(): void
    {
        $collection = $this->collection([]);
        $collection->expects($this->once())->method('addWebsiteGroupDateFilter');

        $this->tool($this->factory($collection))->executeWithContext(
            [],
            new AdminContext(1, ['Magento_Sales::sales'])
        );
    }

    /**
     * A coupon rule whose own code was pasted into its texts by the merchant must have
     * that code redacted everywhere — otherwise the "codes are never returned" guarantee
     * is defeated by the rule's own wording.
     */
    public function testRedactsCouponCodeLeakedIntoTexts(): void
    {
        $coupon = $this->createMock(Coupon::class);
        $coupon->method('getCode')->willReturn('H20');

        $rule = $this->rule([
            ['name', null, 'Water bottle sale (code H20)'],
            ['description', null, 'Use promo code h20 at checkout'],
            ['coupon_type', null, (string)Rule::COUPON_TYPE_SPECIFIC],
        ], 'Enter H20 for $4 off');
        $rule->method('getPrimaryCoupon')->willReturn($coupon);

        $tool = $this->tool($this->factory($this->collection([$rule])));

        $guest = $tool->execute([])['promotions'][0];
        $admin = $tool->executeWithContext([], new AdminContext(1, ['Magento_Backend::all']))['promotions'][0];

        $this->assertStringNotContainsStringIgnoringCase('H20', $guest['label']);
        $this->assertStringNotContainsStringIgnoringCase('H20', $admin['label']);
        $this->assertStringNotContainsStringIgnoringCase('H20', $admin['name']);
        $this->assertStringNotContainsStringIgnoringCase('H20', $admin['description']);
    }

    /**
     * A no-coupon rule must not have its primary coupon loaded at all — there is
     * nothing to redact, and getPrimaryCoupon() would cost a pointless DB round trip.
     */
    public function testNoCouponRuleTextIsReturnedVerbatim(): void
    {
        $rule = $this->rule([
            ['coupon_type', null, (string)Rule::COUPON_TYPE_NO_COUPON],
        ], 'Free shipping on any purchase over $50');
        $rule->expects($this->never())->method('getPrimaryCoupon');

        $result = $this->tool($this->factory($this->collection([$rule])))->execute([]);

        $this->assertSame('Free shipping on any purchase over $50', $result['promotions'][0]['label']);
        $this->assertFalse($result['promotions'][0]['coupon_required']);
    }

    /**
     * @param CollectionFactory $collectionFactory Rule collection factory.
     */
    private function tool(CollectionFactory $collectionFactory): ListActivePromotions
    {
        $timezone = $this->createMock(TimezoneInterface::class);
        $timezone->method('date')->willReturn(new \DateTime('2026-07-07'));

        return new ListActivePromotions($collectionFactory, $timezone, $this->storeManagerMock());
    }

    /**
     * @param array $dataMap getData() return map.
     * @param string|false $storeLabel getStoreLabel() result.
     */
    private function rule(array $dataMap, string|false $storeLabel): Rule&MockObject
    {
        $rule = $this->createMock(Rule::class);
        $rule->method('getData')->willReturnMap($dataMap);
        $rule->method('getStoreLabel')->willReturn($storeLabel);

        return $rule;
    }

    /**
     * @param Rule[] $rules Rules the collection iterates over.
     */
    private function collection(array $rules): Collection&MockObject
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($rules));

        return $collection;
    }

    /**
     * @param Collection $collection Collection the factory creates.
     */
    private function factory(Collection $collection): CollectionFactory
    {
        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        return $factory;
    }
}
