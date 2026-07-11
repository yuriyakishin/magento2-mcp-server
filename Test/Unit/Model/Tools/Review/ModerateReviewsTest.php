<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Tools\Review;

use Magento\Review\Model\ResourceModel\Review as ReviewResource;
use Magento\Review\Model\Review;
use Magento\Review\Model\ReviewFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\Tools\Review\ModerateReviews;

class ModerateReviewsTest extends TestCase
{
    /**
     * review_moderate is a write tool and must require the "All Reviews" ACL resource.
     */
    public function testRequiresAllReviewsAclResource(): void
    {
        $tool = new ModerateReviews(
            $this->createMock(ReviewFactory::class),
            $this->createMock(ReviewResource::class)
        );

        $this->assertSame('Magento_Review::reviews_all', $tool->getRequiredAclResource());
        $this->assertSame('review_moderate', $tool->getName());
    }

    /**
     * Missing/invalid ids, an oversized batch and an unknown status must fail validation.
     */
    public function testThrowsOnInvalidArguments(): void
    {
        $tool = new ModerateReviews(
            $this->createMock(ReviewFactory::class),
            $this->createMock(ReviewResource::class)
        );

        $invalid = [
            ['status' => 'approved'],
            ['review_ids' => [], 'status' => 'approved'],
            ['review_ids' => ['abc'], 'status' => 'approved'],
            ['review_ids' => [0], 'status' => 'approved'],
            ['review_ids' => range(1, 21), 'status' => 'approved'],
            ['review_ids' => [1]],
            ['review_ids' => [1], 'status' => 'deleted'],
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
     * One unknown id aborts the whole batch before any review is saved.
     */
    public function testUnknownIdAbortsBatchWithoutSaving(): void
    {
        $existing = $this->mockReview(10);
        $missing = $this->mockReview(null);

        $reviewFactory = $this->createMock(ReviewFactory::class);
        $reviewFactory->method('create')->willReturnOnConsecutiveCalls($existing, $missing);

        $reviewResource = $this->createMock(ReviewResource::class);
        $reviewResource->expects($this->never())->method('save');

        $tool = new ModerateReviews($reviewFactory, $reviewResource);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Review with id 11 does not exist. No reviews were changed.');

        $tool->execute(['review_ids' => [10, 11], 'status' => 'approved']);
    }

    /**
     * Approving sets STATUS_APPROVED on every review, saves it and re-aggregates the
     * product rating summary; the status is the only thing ever written.
     */
    public function testApprovesBatchAndAggregates(): void
    {
        $review1 = $this->mockReview(10);
        $review1->expects($this->once())->method('setStatusId')->with(Review::STATUS_APPROVED);
        $review1->expects($this->once())->method('aggregate');
        $review2 = $this->mockReview(11);
        $review2->expects($this->once())->method('setStatusId')->with(Review::STATUS_APPROVED);
        $review2->expects($this->once())->method('aggregate');

        $reviewFactory = $this->createMock(ReviewFactory::class);
        $reviewFactory->method('create')->willReturnOnConsecutiveCalls($review1, $review2);

        $reviewResource = $this->createMock(ReviewResource::class);
        $reviewResource->expects($this->exactly(2))->method('save');

        $tool = new ModerateReviews($reviewFactory, $reviewResource);

        $result = $tool->execute(['review_ids' => [10, 11], 'status' => 'approved']);

        $this->assertSame(2, $result['count']);
        $this->assertSame(['review_id' => 10, 'status' => 'approved'], $result['moderated'][0]);
        $this->assertSame(['review_id' => 11, 'status' => 'approved'], $result['moderated'][1]);
    }

    /**
     * Rejecting maps to Magento's STATUS_NOT_APPROVED — a reversible hide, not deletion.
     */
    public function testRejectsReviewWithNotApprovedStatus(): void
    {
        $review = $this->mockReview(10);
        $review->expects($this->once())->method('setStatusId')->with(Review::STATUS_NOT_APPROVED);

        $reviewFactory = $this->createMock(ReviewFactory::class);
        $reviewFactory->method('create')->willReturn($review);

        $tool = new ModerateReviews($reviewFactory, $this->createMock(ReviewResource::class));

        $result = $tool->execute(['review_ids' => [10], 'status' => 'rejected']);

        $this->assertSame([['review_id' => 10, 'status' => 'rejected']], $result['moderated']);
    }

    /**
     * Builds a Review model mock. setStatusId is a magic accessor (AbstractModel::__call),
     * so it must be attached via addMethods, same as the Model/Oauth/* mocks.
     *
     * @param int|null $id null simulates a review that failed to load
     */
    private function mockReview(?int $id): Review&MockObject
    {
        $review = $this->getMockBuilder(Review::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'aggregate'])
            ->addMethods(['setStatusId'])
            ->getMock();
        $review->method('getId')->willReturn($id);

        return $review;
    }
}
