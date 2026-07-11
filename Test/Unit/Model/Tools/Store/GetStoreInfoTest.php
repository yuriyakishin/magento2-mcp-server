<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Tools\Store;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\Tools\Store\GetStoreInfo;

class GetStoreInfoTest extends TestCase
{
    /**
     * store_info is a public tool and must not require an ACL resource.
     */
    public function testRequiresNoAclResource(): void
    {
        $tool = new GetStoreInfo(
            $this->createMock(StoreManagerInterface::class),
            $this->createMock(ScopeConfigInterface::class)
        );

        $this->assertNull($tool->getRequiredAclResource());
        $this->assertSame('store_info', $tool->getName());
    }

    /**
     * The store block, contacts and active methods must come back; only code + title of
     * each method may leave the server — configuration keys like api_key must not.
     */
    public function testReturnsStoreFactsWithoutMethodSecrets(): void
    {
        $store = $this->createMock(Store::class);
        $store->method('getName')->willReturn('Demo Store');
        $store->method('getBaseUrl')->willReturn('https://demo.example.com/');
        $store->method('getBaseCurrencyCode')->willReturn('USD');
        $store->method('getDefaultCurrencyCode')->willReturn('USD');
        $store->method('getAvailableCurrencyCodes')->willReturn(['USD', 'EUR']);

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap([
            ['general/locale/code', 'store', null, 'en_US'],
            ['general/locale/timezone', 'store', null, 'America/New_York'],
            ['general/store_information/name', 'store', null, 'Demo Store'],
            ['general/store_information/phone', 'store', null, '+15550001122'],
            ['trans_email/ident_general/email', 'store', null, 'shop@example.com'],
            ['general/store_information/country_id', 'store', null, 'US'],
            ['general/store_information/city', 'store', null, 'New York'],
            ['general/store_information/street_line1', 'store', null, null],
            ['carriers', 'store', null, [
                'flatrate' => ['active' => '1', 'title' => 'Flat Rate', 'price' => '5.00'],
                'ups' => ['active' => '0', 'title' => 'UPS'],
            ]],
            ['payment', 'store', null, [
                'checkmo' => ['active' => '1', 'title' => 'Check / Money order'],
                'stripe' => ['active' => '1', 'title' => 'Cards', 'api_key' => 'sk_live_SECRET'],
                'paypal' => ['active' => '0', 'title' => 'PayPal'],
            ]],
        ]);

        $tool = new GetStoreInfo($storeManager, $scopeConfig);

        $result = $tool->execute([]);

        $this->assertSame('Demo Store', $result['store']['name']);
        $this->assertSame('USD', $result['store']['base_currency']);
        $this->assertSame(['USD', 'EUR'], $result['store']['allowed_currencies']);
        $this->assertSame('en_US', $result['store']['locale']);
        $this->assertSame('shop@example.com', $result['contact']['email']);

        $this->assertSame([['code' => 'flatrate', 'title' => 'Flat Rate']], $result['shipping_methods']);
        $this->assertSame(
            [
                ['code' => 'checkmo', 'title' => 'Check / Money order'],
                ['code' => 'stripe', 'title' => 'Cards'],
            ],
            $result['payment_methods']
        );
        $this->assertStringNotContainsString('sk_live_SECRET', json_encode($result));
    }

    /**
     * A store with no active methods (or missing config sections) must yield empty
     * lists, not an error.
     */
    public function testHandlesMissingMethodSections(): void
    {
        $store = $this->createMock(Store::class);
        $store->method('getAvailableCurrencyCodes')->willReturn([]);

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn(null);

        $tool = new GetStoreInfo($storeManager, $scopeConfig);

        $result = $tool->execute([]);

        $this->assertSame([], $result['shipping_methods']);
        $this->assertSame([], $result['payment_methods']);
    }
}
