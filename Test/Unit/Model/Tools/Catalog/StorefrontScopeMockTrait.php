<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Tools\Catalog;

use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Yu\McpServer\Model\Tools\Catalog\StorefrontScope;

/**
 * Builds a real StorefrontScope over a mocked current store, for tool tests.
 */
trait StorefrontScopeMockTrait
{
    /**
     * @param int $websiteId Website id of the current store.
     * @param int $rootCategoryId Root category id of the current store.
     */
    private function storefrontScope(int $websiteId = 1, int $rootCategoryId = 2): StorefrontScope
    {
        return new StorefrontScope($this->storeManagerMock($websiteId, $rootCategoryId));
    }

    /**
     * @param int $websiteId Website id of the current store.
     * @param int $rootCategoryId Root category id of the current store.
     * @param int $storeId Id of the current store.
     */
    private function storeManagerMock(
        int $websiteId = 1,
        int $rootCategoryId = 2,
        int $storeId = 1
    ): StoreManagerInterface {
        $store = $this->createMock(Store::class);
        $store->method('getWebsiteId')->willReturn($websiteId);
        $store->method('getRootCategoryId')->willReturn($rootCategoryId);
        $store->method('getId')->willReturn($storeId);

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return $storeManager;
    }
}
