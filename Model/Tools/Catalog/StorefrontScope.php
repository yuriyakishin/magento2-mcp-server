<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Tools\Catalog;

use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The anonymous ("customer") view of the catalog, shared by the public catalog tools: what a
 * visitor of the current store can actually reach. A product counts only when it is enabled,
 * assigned to the current website and visible in the place being asked about; a category
 * only when it is active and belongs to the current store's tree. Anything outside that is
 * answered exactly like something that doesn't exist, so anonymous calls can't be used to
 * discover hidden catalog data. Not a tool.
 */
class StorefrontScope
{
    /** Product shown in category listings. */
    public const VISIBLE_IN_CATALOG = [Visibility::VISIBILITY_IN_CATALOG, Visibility::VISIBILITY_BOTH];

    /** Product found by storefront search. */
    public const VISIBLE_IN_SEARCH = [Visibility::VISIBILITY_IN_SEARCH, Visibility::VISIBILITY_BOTH];

    /** Product has its own page. */
    public const VISIBLE_INDIVIDUALLY = [
        Visibility::VISIBILITY_IN_CATALOG,
        Visibility::VISIBILITY_IN_SEARCH,
        Visibility::VISIBILITY_BOTH,
    ];

    public function __construct(
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Restricts a product collection to products a visitor of the current store can see.
     *
     * @param ProductCollection $collection Collection to filter.
     * @param int[] $visibilities Accepted visibility values (one of the class constants).
     */
    public function filterProductCollection(ProductCollection $collection, array $visibilities): void
    {
        $collection->addAttributeToFilter('status', Status::STATUS_ENABLED);
        $collection->addAttributeToFilter('visibility', ['in' => $visibilities]);
        $collection->addWebsiteFilter([$this->currentWebsiteId()]);
    }

    /**
     * Whether a single product is visible to a visitor of the current store.
     *
     * @param ProductInterface $product Product loaded in the current store scope.
     * @param int[]|null $visibilities Accepted visibility values, or null to skip the
     *     visibility check (e.g. stock of a variant shown on its parent's page).
     */
    public function isProductVisible(ProductInterface $product, ?array $visibilities): bool
    {
        if ((int)$product->getStatus() !== Status::STATUS_ENABLED) {
            return false;
        }

        $websiteIds = array_map('intval', (array)$product->getWebsiteIds());
        if (!in_array($this->currentWebsiteId(), $websiteIds, true)) {
            return false;
        }

        return $visibilities === null || in_array((int)$product->getVisibility(), $visibilities, true);
    }

    /**
     * Whether a category is active and part of the current store's category tree.
     *
     * @param CategoryInterface $category Category to check.
     */
    public function isCategoryVisible(CategoryInterface $category): bool
    {
        if (!(bool)$category->getIsActive()) {
            return false;
        }

        // Strictly below the root: the root category itself has no storefront page.
        return str_starts_with((string)$category->getPath(), '1/' . $this->rootCategoryId() . '/');
    }

    /**
     * Root category id of the current store.
     */
    public function rootCategoryId(): int
    {
        return (int)$this->storeManager->getStore()->getRootCategoryId();
    }

    /**
     * Website id of the current store.
     */
    private function currentWebsiteId(): int
    {
        return (int)$this->storeManager->getStore()->getWebsiteId();
    }
}
