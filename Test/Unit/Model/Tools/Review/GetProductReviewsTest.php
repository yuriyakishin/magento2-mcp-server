<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Tools\Review;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Review\Model\Rating\Option\Vote;
use Magento\Review\Model\ResourceModel\Review\Collection;
use Magento\Review\Model\ResourceModel\Review\CollectionFactory;
use Magento\Review\Model\Review;
use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\Auth\AdminContext;
use Yu\McpServer\Model\Tools\Review\GetProductReviews;
use Yu\McpServer\Test\Unit\Model\StoreTimeMockTrait;
use Yu\McpServer\Test\Unit\Model\Tools\Catalog\StorefrontScopeMockTrait;

class GetProductReviewsTest extends TestCase
{
    use StoreTimeMockTrait;

    use StorefrontScopeMockTrait;

    /**
     * review_list_for_product is a public tool and must not require an ACL resource.
     */
    public function testRequiresNoAclResource(): void
    {
        $tool = new GetProductReviews(
            $this->createMock(ProductRepositoryInterface::class),
            $this->createMock(CollectionFactory::class),
            $this->storefrontScope(),
            $this->storeManagerMock(),
            $this->storeTime()
        );

        $this->assertNull($tool->getRequiredAclResource());
        $this->assertSame('review_list_for_product', $tool->getName());
    }

    /**
     * A missing "sku" argument and an invalid "limit" must fail validation.
     */
    public function testThrowsOnInvalidArguments(): void
    {
        $tool = new GetProductReviews(
            $this->createMock(ProductRepositoryInterface::class),
            $this->createMock(CollectionFactory::class),
            $this->storefrontScope(),
            $this->storeManagerMock(),
            $this->storeTime()
        );

        foreach ([[], ['sku' => ''], ['sku' => 'A-1', 'limit' => -5]] as $arguments) {
            try {
                $tool->execute($arguments);
                $this->fail('Expected InvalidArgumentException for: ' . json_encode($arguments));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * An unknown SKU is a business-logic error, reported as a RuntimeException.
     */
    public function testThrowsRuntimeExceptionForUnknownSku(): void
    {
        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('get')->willThrowException(
            NoSuchEntityException::singleField('sku', 'missing')
        );

        $tool = new GetProductReviews(
            $productRepository,
            $this->createMock(CollectionFactory::class),
            $this->storefrontScope(),
            $this->storeManagerMock(),
            $this->storeTime()
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Product with SKU "missing" does not exist.');

        $tool->execute(['sku' => 'missing']);
    }

    /**
     * Approved reviews are returned with an averaged 1-5 star rating; the approved-only
     * status filter must be applied to the collection.
     */
    public function testReturnsApprovedReviewsWithAverageRating(): void
    {
        $vote1 = $this->createMock(Vote::class);
        $vote1->method('getData')->willReturnMap([['value', null, '4']]);
        $vote2 = $this->createMock(Vote::class);
        $vote2->method('getData')->willReturnMap([['value', null, '5']]);

        $review = $this->createMock(Review::class);
        $review->method('getData')->willReturnMap([
            ['title', null, 'Great'],
            ['detail', null, 'Very comfy sofa'],
            ['nickname', null, 'Yuriy'],
            ['created_at', null, '2026-07-01 12:00:00'],
            ['rating_votes', null, [$vote1, $vote2]],
        ]);

        $reviewWithoutVotes = $this->createMock(Review::class);
        $reviewWithoutVotes->method('getData')->willReturnMap([
            ['title', null, 'Ok'],
            ['detail', null, 'Fine'],
            ['nickname', null, 'Anna'],
            ['created_at', null, '2026-06-01 12:00:00'],
            ['rating_votes', null, null],
        ]);

        $collection = $this->createMock(Collection::class);
        $collection->expects($this->once())->method('addStatusFilter')->with(Review::STATUS_APPROVED);
        $collection->expects($this->once())->method('addEntityFilter')->with('product', 42);
        $collection->method('setDateOrder')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('addRateVotes')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$review, $reviewWithoutVotes]));

        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $product = $this->visibleProduct();
        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('get')->with('SOFA-1')->willReturn($product);

        $tool = new GetProductReviews($productRepository, $collectionFactory, $this->storefrontScope(), $this->storeManagerMock(), $this->storeTime());

        $result = $tool->execute(['sku' => 'SOFA-1']);

        $this->assertSame(2, $result['count']);
        $this->assertSame(4.5, $result['reviews'][0]['rating']);
        $this->assertSame('Great', $result['reviews'][0]['title']);
        $this->assertNull($result['reviews'][1]['rating']);
    }

    /**
     * A product without reviews yields an empty list, not an error.
     */
    public function testReturnsEmptyListWhenNoReviews(): void
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('setDateOrder')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('addRateVotes')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator([]));

        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $product = $this->visibleProduct();
        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('get')->willReturn($product);

        $tool = new GetProductReviews($productRepository, $collectionFactory, $this->storefrontScope(), $this->storeManagerMock(), $this->storeTime());

        $result = $tool->execute(['sku' => 'SOFA-1']);

        $this->assertSame([], $result['reviews']);
        $this->assertSame(0, $result['count']);
    }

    /**
     * The customer view: a product without a page in this store is reported as missing,
     * and its reviews are never loaded.
     */
    public function testAnonymousCallerSeesHiddenProductAsMissing(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getStatus')->willReturn(Status::STATUS_DISABLED);
        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('get')->willReturn($product);
        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->expects($this->never())->method('create');

        $tool = new GetProductReviews(
            $productRepository,
            $collectionFactory,
            $this->storefrontScope(),
            $this->storeManagerMock(),
            $this->storeTime()
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Product with SKU "HIDDEN" does not exist.');

        $tool->execute(['sku' => 'HIDDEN']);
    }

    /**
     * Anonymous callers get only reviews published to the current store; an admin with the
     * catalog ACL gets reviews from all stores, even for a disabled product.
     */
    public function testStoreFilterAppliesToAnonymousCallersOnly(): void
    {
        $anonymousCollection = $this->emptyReviewCollection();
        $anonymousCollection->expects($this->once())->method('addStoreFilter')->with(1);
        $this->toolFor($this->visibleProduct(), $anonymousCollection)->execute(['sku' => 'SOFA-1']);

        $disabled = $this->createMock(Product::class);
        $disabled->method('getId')->willReturn(43);
        $disabled->method('getStatus')->willReturn(Status::STATUS_DISABLED);
        $adminCollection = $this->emptyReviewCollection();
        $adminCollection->expects($this->never())->method('addStoreFilter');
        $this->toolFor($disabled, $adminCollection)->executeWithContext(
            ['sku' => 'SOFA-2'],
            new AdminContext(1, ['Magento_Catalog::products'])
        );
    }

    /**
     * An enabled product of website 1 with its own page.
     */
    private function visibleProduct(): Product
    {
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn(42);
        $product->method('getStatus')->willReturn(Status::STATUS_ENABLED);
        $product->method('getWebsiteIds')->willReturn([1]);
        $product->method('getVisibility')->willReturn(Visibility::VISIBILITY_BOTH);

        return $product;
    }

    /**
     * Review collection mock that iterates over nothing.
     */
    private function emptyReviewCollection(): Collection&\PHPUnit\Framework\MockObject\MockObject
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('setDateOrder')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('addRateVotes')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator([]));

        return $collection;
    }

    /**
     * @param Product $product Product the repository returns.
     * @param Collection $collection Review collection the factory creates.
     */
    private function toolFor(Product $product, Collection $collection): GetProductReviews
    {
        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('get')->willReturn($product);
        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        return new GetProductReviews(
            $productRepository,
            $collectionFactory,
            $this->storefrontScope(),
            $this->storeManagerMock(),
            $this->storeTime()
        );
    }
}
