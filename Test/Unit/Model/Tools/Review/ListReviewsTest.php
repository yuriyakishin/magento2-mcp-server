<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Tools\Review;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Review\Model\Rating\Option\Vote;
use Magento\Review\Model\ResourceModel\Review\Collection;
use Magento\Review\Model\ResourceModel\Review\CollectionFactory;
use Magento\Review\Model\Review;
use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\Tools\Review\ListReviews;

class ListReviewsTest extends TestCase
{
    /**
     * review_list exposes unmoderated review texts — it must require the admin
     * "All Reviews" ACL resource.
     */
    public function testRequiresAllReviewsAclResource(): void
    {
        $tool = $this->tool();

        $this->assertSame('Magento_Review::reviews_all', $tool->getRequiredAclResource());
        $this->assertSame('review_list', $tool->getName());
    }

    /**
     * Invalid filter arguments must fail validation before any collection is built.
     */
    public function testThrowsOnInvalidArguments(): void
    {
        $tool = $this->tool();

        $invalid = [
            ['status' => 'published'],
            ['created_from' => '07.07.2026'],
            ['created_to' => '2026-13-45'],
            ['rating_min' => 0],
            ['rating_max' => 6],
            ['rating_min' => 4, 'rating_max' => 2],
            ['limit' => -5],
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
     * Status and date filters are pushed into the collection; each review comes back with
     * its status label, averaged rating and the reviewed product.
     */
    public function testReturnsReviewsWithStatusAndDateFilters(): void
    {
        $review = $this->reviewMock([
            'review_id' => '7',
            'created_at' => '2026-07-05 10:00:00',
            'status_id' => (string) Review::STATUS_PENDING,
            'nickname' => 'Yuriy',
            'title' => 'Great',
            'detail' => 'Very comfy sofa',
            'entity_pk_value' => '42',
        ], [4, 5]);

        $collection = $this->createMock(Collection::class);
        $collection->expects($this->once())->method('addStatusFilter')->with(Review::STATUS_PENDING);
        $collection->expects($this->exactly(2))->method('addFieldToFilter')->willReturnCallback(
            function (string $field, array $condition) use ($collection) {
                $this->assertSame('main_table.created_at', $field);
                $this->assertContains($condition, [
                    ['gteq' => '2026-07-01'],
                    ['lteq' => '2026-07-07 23:59:59'],
                ]);
                return $collection;
            }
        );
        $collection->method('setDateOrder')->willReturnSelf();
        $collection->expects($this->once())->method('setPageSize')->with(20)->willReturnSelf();
        $collection->expects($this->once())->method('setCurPage')->with(1)->willReturnSelf();
        $collection->method('addRateVotes')->willReturnSelf();
        $collection->method('getSize')->willReturn(1);
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$review]));

        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $tool = new ListReviews($collectionFactory, $this->productCollectionFactoryMock([
            42 => ['sku' => 'SOFA-1', 'name' => 'Sofa'],
        ]));

        $result = $tool->execute([
            'status' => 'pending',
            'created_from' => '2026-07-01',
            'created_to' => '2026-07-07',
        ]);

        $this->assertSame(1, $result['count']);
        $this->assertSame(1, $result['total']);
        $this->assertSame(7, $result['reviews'][0]['review_id']);
        $this->assertSame('pending', $result['reviews'][0]['status']);
        $this->assertSame(4.5, $result['reviews'][0]['rating']);
        $this->assertSame('Very comfy sofa', $result['reviews'][0]['text']);
        $this->assertSame(['sku' => 'SOFA-1', 'name' => 'Sofa'], $result['reviews'][0]['product']);
    }

    /**
     * The rating filter is applied within the fetched page: out-of-range and unrated
     * reviews are dropped, while "total" still reflects the status/date filter only.
     */
    public function testRatingFilterDropsOutOfRangeAndUnratedReviews(): void
    {
        $negative = $this->reviewMock([
            'review_id' => '1',
            'created_at' => '2026-07-05 10:00:00',
            'status_id' => (string) Review::STATUS_APPROVED,
            'nickname' => 'Anna',
            'title' => 'Bad',
            'detail' => 'Broke in a week',
            'entity_pk_value' => '42',
        ], [2]);
        $positive = $this->reviewMock([
            'review_id' => '2',
            'created_at' => '2026-07-04 10:00:00',
            'status_id' => (string) Review::STATUS_APPROVED,
            'nickname' => 'Yuriy',
            'title' => 'Great',
            'detail' => 'Love it',
            'entity_pk_value' => '42',
        ], [5]);
        $unrated = $this->reviewMock([
            'review_id' => '3',
            'created_at' => '2026-07-03 10:00:00',
            'status_id' => (string) Review::STATUS_APPROVED,
            'nickname' => 'Olha',
            'title' => 'Meh',
            'detail' => 'No stars given',
            'entity_pk_value' => '42',
        ], null);

        $collection = $this->createMock(Collection::class);
        $collection->expects($this->never())->method('addStatusFilter');
        $collection->method('setDateOrder')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('setCurPage')->willReturnSelf();
        $collection->method('addRateVotes')->willReturnSelf();
        $collection->method('getSize')->willReturn(3);
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$negative, $positive, $unrated]));

        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $tool = new ListReviews($collectionFactory, $this->productCollectionFactoryMock([
            42 => ['sku' => 'SOFA-1', 'name' => 'Sofa'],
        ]));

        $result = $tool->execute(['rating_max' => 2]);

        $this->assertSame(1, $result['count']);
        $this->assertSame(3, $result['total']);
        $this->assertSame(1, $result['reviews'][0]['review_id']);
        $this->assertSame(2.0, $result['reviews'][0]['rating']);
    }

    /**
     * No matching reviews yields an empty list (and no product query), not an error.
     */
    public function testReturnsEmptyListWhenNoReviews(): void
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('setDateOrder')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('setCurPage')->willReturnSelf();
        $collection->method('addRateVotes')->willReturnSelf();
        $collection->method('getSize')->willReturn(0);
        $collection->method('getIterator')->willReturn(new \ArrayIterator([]));

        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $productCollectionFactory = $this->createMock(ProductCollectionFactory::class);
        $productCollectionFactory->expects($this->never())->method('create');

        $tool = new ListReviews($collectionFactory, $productCollectionFactory);

        $result = $tool->execute([]);

        $this->assertSame([], $result['reviews']);
        $this->assertSame(0, $result['count']);
        $this->assertSame(0, $result['total']);
    }

    /**
     * Builds the tool with dependencies that are never exercised.
     */
    private function tool(): ListReviews
    {
        return new ListReviews(
            $this->createMock(CollectionFactory::class),
            $this->createMock(ProductCollectionFactory::class)
        );
    }

    /**
     * Builds a review item mock exposing the given raw data and rating votes.
     *
     * @param array<string, string> $data
     * @param int[]|null $voteValues null means the review carries no votes at all
     */
    private function reviewMock(array $data, ?array $voteValues): Review
    {
        $votes = null;
        if ($voteValues !== null) {
            $votes = [];
            foreach ($voteValues as $value) {
                $vote = $this->createMock(Vote::class);
                $vote->method('getData')->willReturnMap([['value', null, (string) $value]]);
                $votes[] = $vote;
            }
        }

        $map = [['rating_votes', null, $votes]];
        foreach ($data as $key => $value) {
            $map[] = [$key, null, $value];
        }

        $review = $this->createMock(Review::class);
        $review->method('getData')->willReturnMap($map);

        return $review;
    }

    /**
     * Builds a product collection factory whose collection returns the given products.
     *
     * @param array<int, array{sku: string, name: string}> $products id => sku/name
     */
    private function productCollectionFactoryMock(array $products): ProductCollectionFactory
    {
        $items = [];
        foreach ($products as $id => $productData) {
            $product = $this->createMock(Product::class);
            $product->method('getId')->willReturn($id);
            $product->method('getSku')->willReturn($productData['sku']);
            $product->method('getName')->willReturn($productData['name']);
            $items[] = $product;
        }

        $collection = $this->createMock(ProductCollection::class);
        $collection->method('addIdFilter')->willReturnSelf();
        $collection->method('addAttributeToSelect')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($items));

        $factory = $this->createMock(ProductCollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        return $factory;
    }
}
