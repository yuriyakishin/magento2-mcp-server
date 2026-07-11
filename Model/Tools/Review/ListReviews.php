<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Tools\Review;

use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Review\Model\ResourceModel\Review\CollectionFactory;
use Magento\Review\Model\Review;
use Yu\McpServer\Model\ToolInterface;

/**
 * Moderation/analysis counterpart of the public review_list_for_product: lists reviews of the
 * whole store in ANY moderation status (pending included), with date-range and star-rating
 * filters. Unmoderated review texts are back-office data, hence the ACL requirement.
 */
class ListReviews implements ToolInterface
{
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 50;

    private const STATUS_MAP = [
        'approved' => Review::STATUS_APPROVED,
        'pending' => Review::STATUS_PENDING,
        'not_approved' => Review::STATUS_NOT_APPROVED,
    ];

    public function __construct(
        private readonly CollectionFactory $reviewCollectionFactory,
        private readonly ProductCollectionFactory $productCollectionFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'review_list';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Lists customer reviews across the whole store in any moderation status '
            . '(pending, approved, not_approved), newest first, with optional date-range and '
            . 'star-rating filters. Returns review id, status, date, reviewer nickname, title, '
            . 'text, averaged 1-5 rating and the reviewed product (SKU + name). Use it to '
            . 'analyse recent feedback (e.g. rating_max=2 plus a date range for negative '
            . 'reviews of the last week) and to pick review ids for review_moderate. Dates '
            . 'are compared in UTC. The rating filter is applied within each fetched page, so '
            . 'a page may return fewer than "limit" reviews even when more pages exist '
            . '("total" counts status/date matches and ignores the rating filter).';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'enum' => ['all', 'pending', 'approved', 'not_approved'],
                    'description' => 'Moderation status to filter by (default "all"). Use '
                        . '"pending" for the moderation queue.',
                ],
                'created_from' => [
                    'type' => 'string',
                    'description' => 'Only reviews created at or after this UTC date, '
                        . '"YYYY-MM-DD" or "YYYY-MM-DD HH:MM:SS".',
                ],
                'created_to' => [
                    'type' => 'string',
                    'description' => 'Only reviews created at or before this UTC date, '
                        . '"YYYY-MM-DD" (whole day included) or "YYYY-MM-DD HH:MM:SS".',
                ],
                'rating_min' => [
                    'type' => 'integer',
                    'description' => 'Only reviews with an average star rating >= this value '
                        . '(1-5). Reviews without a rating are excluded when set.',
                ],
                'rating_max' => [
                    'type' => 'integer',
                    'description' => 'Only reviews with an average star rating <= this value '
                        . '(1-5). Reviews without a rating are excluded when set.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Reviews per page (default 20, max 50).',
                ],
                'page' => [
                    'type' => 'integer',
                    'description' => 'Page number, starting at 1 (default 1).',
                ],
            ],
            'required' => [],
        ];
    }

    /**
     * Pending/rejected review texts are moderation data — same admin permission as the
     * "All Reviews" admin grid.
     */
    public function getRequiredAclResource(): ?string
    {
        return 'Magento_Review::reviews_all';
    }

    /**
     * @param array $arguments See getInputSchema().
     * @return array{reviews: array<int, array<string, mixed>>, count: int, total: int, page: int}
     */
    public function execute(array $arguments): array
    {
        $status = $this->statusArgument($arguments);
        $createdFrom = $this->dateArgument($arguments, 'created_from');
        $createdTo = $this->dateArgument($arguments, 'created_to');
        [$ratingMin, $ratingMax] = $this->ratingRangeArguments($arguments);
        $limit = $this->positiveIntArgument($arguments, 'limit', self::DEFAULT_LIMIT, self::MAX_LIMIT);
        $page = $this->positiveIntArgument($arguments, 'page', 1, PHP_INT_MAX);

        // A date-only upper bound means "the whole day included".
        if ($createdTo !== null && strlen($createdTo) === 10) {
            $createdTo .= ' 23:59:59';
        }

        $collection = $this->reviewCollectionFactory->create();
        if ($status !== 'all') {
            $collection->addStatusFilter(self::STATUS_MAP[$status]);
        }
        if ($createdFrom !== null) {
            $collection->addFieldToFilter('main_table.created_at', ['gteq' => $createdFrom]);
        }
        if ($createdTo !== null) {
            $collection->addFieldToFilter('main_table.created_at', ['lteq' => $createdTo]);
        }
        $collection->setDateOrder();
        $collection->setPageSize($limit);
        $collection->setCurPage($page);
        $collection->addRateVotes();

        $total = $collection->getSize();

        $reviews = [];
        $productIds = [];
        foreach ($collection as $review) {
            $rating = $this->averageRating($review->getData('rating_votes'));
            if (!$this->matchesRatingRange($rating, $ratingMin, $ratingMax)) {
                continue;
            }
            $productId = (int) $review->getData('entity_pk_value');
            $productIds[] = $productId;
            $reviews[] = [
                'review_id' => (int) $review->getData('review_id'),
                'created_at' => $review->getData('created_at'),
                'status' => array_search((int) $review->getData('status_id'), self::STATUS_MAP, true) ?: 'unknown',
                'nickname' => $review->getData('nickname'),
                'title' => $review->getData('title'),
                'text' => $review->getData('detail'),
                'rating' => $rating,
                'product_id' => $productId,
            ];
        }

        $products = $this->loadProducts($productIds);
        foreach ($reviews as &$review) {
            $review['product'] = $products[$review['product_id']] ?? null;
            unset($review['product_id']);
        }
        unset($review);

        return [
            'reviews' => $reviews,
            'count' => count($reviews),
            'total' => $total,
            'page' => $page,
        ];
    }

    /**
     * Batch-loads SKU and name for the reviewed products (one query for the whole page).
     *
     * @param int[] $productIds
     * @return array<int, array{sku: string, name: string}>
     */
    private function loadProducts(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter($productIds)));
        if ($productIds === []) {
            return [];
        }

        $collection = $this->productCollectionFactory->create();
        $collection->addIdFilter($productIds);
        $collection->addAttributeToSelect('name');

        $products = [];
        foreach ($collection as $product) {
            $products[(int) $product->getId()] = [
                'sku' => $product->getSku(),
                'name' => $product->getName(),
            ];
        }

        return $products;
    }

    /**
     * Averages the review's rating votes into a 1-5 star value (null if the review
     * carries no votes).
     *
     * @param iterable<\Magento\Review\Model\Rating\Option\Vote>|null $votes
     */
    private function averageRating(?iterable $votes): ?float
    {
        if ($votes === null) {
            return null;
        }

        $sum = 0;
        $count = 0;
        foreach ($votes as $vote) {
            $sum += (int) $vote->getData('value');
            $count++;
        }

        return $count > 0 ? round($sum / $count, 1) : null;
    }

    /**
     * Whether an average rating passes the requested min/max bounds. Reviews without a
     * rating only pass when no bound is set at all.
     */
    private function matchesRatingRange(?float $rating, ?int $min, ?int $max): bool
    {
        if ($min === null && $max === null) {
            return true;
        }
        if ($rating === null) {
            return false;
        }

        return ($min === null || $rating >= $min) && ($max === null || $rating <= $max);
    }

    /**
     * Validates the optional "status" argument.
     */
    private function statusArgument(array $arguments): string
    {
        $status = $arguments['status'] ?? 'all';
        if (!is_string($status) || !in_array($status, array_merge(['all'], array_keys(self::STATUS_MAP)), true)) {
            throw new \InvalidArgumentException(
                'Argument "status" must be one of: all, pending, approved, not_approved.'
            );
        }

        return $status;
    }

    /**
     * Validates an optional date argument: "YYYY-MM-DD" or "YYYY-MM-DD HH:MM:SS".
     */
    private function dateArgument(array $arguments, string $key): ?string
    {
        $value = $arguments[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)
            || !preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $value)
            || strtotime($value) === false
        ) {
            throw new \InvalidArgumentException(
                sprintf('Argument "%s" must be a valid "YYYY-MM-DD" or "YYYY-MM-DD HH:MM:SS" date.', $key)
            );
        }

        return $value;
    }

    /**
     * Validates the optional rating_min/rating_max arguments (integers 1-5, min <= max).
     *
     * @return array{0: ?int, 1: ?int}
     */
    private function ratingRangeArguments(array $arguments): array
    {
        $range = [];
        foreach (['rating_min', 'rating_max'] as $key) {
            $value = $arguments[$key] ?? null;
            if ($value !== null && (!is_numeric($value) || (int) $value != $value || $value < 1 || $value > 5)) {
                throw new \InvalidArgumentException(
                    sprintf('Argument "%s" must be an integer between 1 and 5.', $key)
                );
            }
            $range[] = $value === null ? null : (int) $value;
        }
        if ($range[0] !== null && $range[1] !== null && $range[0] > $range[1]) {
            throw new \InvalidArgumentException('Argument "rating_min" must not exceed "rating_max".');
        }

        return $range;
    }

    /**
     * Validates an optional positive-integer argument, applying a default and a cap.
     */
    private function positiveIntArgument(array $arguments, string $key, int $default, int $max): int
    {
        if (!isset($arguments[$key])) {
            return $default;
        }
        if (!is_numeric($arguments[$key]) || (int) $arguments[$key] < 1) {
            throw new \InvalidArgumentException(sprintf('Argument "%s" must be a positive integer.', $key));
        }

        return min((int) $arguments[$key], $max);
    }
}
