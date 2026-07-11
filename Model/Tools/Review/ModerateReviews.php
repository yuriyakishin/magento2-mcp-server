<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Tools\Review;

use Magento\Review\Model\ResourceModel\Review as ReviewResource;
use Magento\Review\Model\Review;
use Magento\Review\Model\ReviewFactory;
use Yu\McpServer\Model\WriteToolInterface;

/**
 * Status-only moderation: approve or reject existing reviews, both reversible (rejecting
 * is not deletion). The customer's words are untouchable — this tool must never gain the
 * ability to edit review text, nickname or rating.
 */
class ModerateReviews implements WriteToolInterface
{
    private const MAX_REVIEWS = 20;

    private const STATUS_MAP = [
        'approved' => Review::STATUS_APPROVED,
        'rejected' => Review::STATUS_NOT_APPROVED,
    ];

    public function __construct(
        private readonly ReviewFactory $reviewFactory,
        private readonly ReviewResource $reviewResource
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'review_moderate';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Changes ONLY the moderation status of existing customer reviews: approve '
            . '(publish on the storefront) or reject (hide). Both directions are reversible '
            . '— rejecting is not deletion. Never edits review text, nickname or rating. '
            . 'Fails without changes if any review id is unknown. Typical flow: pick review '
            . 'ids via review_list, then approve/reject them in one call (max 20 ids).';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'review_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'Ids of the reviews to moderate (1 to 20), as returned '
                        . 'by review_list.',
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => ['approved', 'rejected'],
                    'description' => 'New moderation status for all listed reviews.',
                ],
            ],
            'required' => ['review_ids', 'status'],
        ];
    }

    /**
     * Same admin permission as the "All Reviews" admin grid.
     */
    public function getRequiredAclResource(): ?string
    {
        return 'Magento_Review::reviews_all';
    }

    /**
     * @param array $arguments See getInputSchema().
     * @return array{moderated: array<int, array{review_id: int, status: string}>, count: int}
     */
    public function execute(array $arguments): array
    {
        $reviewIds = $this->reviewIdsArgument($arguments);
        $status = $this->statusArgument($arguments);

        // Resolve every review before touching any of them, so one bad id can't leave the
        // batch half-applied.
        $reviews = [];
        foreach ($reviewIds as $reviewId) {
            $review = $this->reviewFactory->create();
            $this->reviewResource->load($review, $reviewId);
            if (!$review->getId()) {
                throw new \RuntimeException(
                    sprintf('Review with id %d does not exist. No reviews were changed.', $reviewId)
                );
            }
            $reviews[$reviewId] = $review;
        }

        $moderated = [];
        foreach ($reviews as $reviewId => $review) {
            $review->setStatusId(self::STATUS_MAP[$status]);
            $this->reviewResource->save($review);
            // Refreshes the product's aggregated rating summary shown on the storefront.
            $review->aggregate();

            $moderated[] = [
                'review_id' => $reviewId,
                'status' => $status,
            ];
        }

        return [
            'moderated' => $moderated,
            'count' => count($moderated),
        ];
    }

    /**
     * Validates the "review_ids" argument: a non-empty, de-duplicated list of positive
     * integers, capped at 20 per call.
     *
     * @return int[]
     */
    private function reviewIdsArgument(array $arguments): array
    {
        $reviewIds = $arguments['review_ids'] ?? null;
        if (!is_array($reviewIds) || $reviewIds === []) {
            throw new \InvalidArgumentException('Argument "review_ids" is required and must be a non-empty array.');
        }

        $clean = [];
        foreach ($reviewIds as $reviewId) {
            if (!is_numeric($reviewId) || (int) $reviewId != $reviewId || (int) $reviewId < 1) {
                throw new \InvalidArgumentException('Argument "review_ids" must contain only positive integers.');
            }
            $clean[] = (int) $reviewId;
        }
        $clean = array_values(array_unique($clean));

        if (count($clean) > self::MAX_REVIEWS) {
            throw new \InvalidArgumentException(
                sprintf('Argument "review_ids" must not contain more than %d ids per call.', self::MAX_REVIEWS)
            );
        }

        return $clean;
    }

    /**
     * Validates the "status" argument.
     */
    private function statusArgument(array $arguments): string
    {
        $status = $arguments['status'] ?? null;
        if (!is_string($status) || !isset(self::STATUS_MAP[$status])) {
            throw new \InvalidArgumentException('Argument "status" must be "approved" or "rejected".');
        }

        return $status;
    }
}
