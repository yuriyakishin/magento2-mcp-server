<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Tools\Review;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Review\Model\Rating\Option\Vote;
use Magento\Review\Model\ResourceModel\Review\Collection;
use Magento\Review\Model\ResourceModel\Review\CollectionFactory;
use Magento\Review\Model\Review;
use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\Tools\Review\GetProductReviews;

class GetProductReviewsTest extends TestCase
{
    /**
     * review_list_for_product is a public tool and must not require an ACL resource.
     */
    public function testRequiresNoAclResource(): void
    {
        $tool = new GetProductReviews(
            $this->createMock(ProductRepositoryInterface::class),
            $this->createMock(CollectionFactory::class)
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
            $this->createMock(CollectionFactory::class)
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
            $this->createMock(CollectionFactory::class)
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

        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn(42);
        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('get')->with('SOFA-1')->willReturn($product);

        $tool = new GetProductReviews($productRepository, $collectionFactory);

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

        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn(42);
        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('get')->willReturn($product);

        $tool = new GetProductReviews($productRepository, $collectionFactory);

        $result = $tool->execute(['sku' => 'SOFA-1']);

        $this->assertSame([], $result['reviews']);
        $this->assertSame(0, $result['count']);
    }
}
