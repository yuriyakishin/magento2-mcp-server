<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Tools\Review;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Review\Model\ResourceModel\Review\CollectionFactory;
use Magento\Review\Model\Review;
use Magento\Store\Model\StoreManagerInterface;
use Yu\McpServer\Model\Auth\AdminContext;
use Yu\McpServer\Model\ContextAwareToolInterface;
use Yu\McpServer\Model\StoreTime;
use Yu\McpServer\Model\Tools\Catalog\StorefrontScope;

/**
 * Returns APPROVED product reviews only — pending and rejected reviews are moderation
 * data, not public content. (A future approve-reviews write tool is a separate,
 * ACL-gated decision; this tool must stay approved-only regardless.)
 *
 * Two storefronts (see ContextAwareToolInterface): anonymous callers get the reviews shown
 * on the product's page in this store — a product without such a page is reported as
 * missing (see StorefrontScope), and only reviews published to this store are returned.
 * An admin with the catalog ACL gets the approved reviews of any product, from all stores.
 */
class GetProductReviews implements ContextAwareToolInterface
{
    private const DEFAULT_LIMIT = 10;
    private const MAX_LIMIT = 50;

    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CollectionFactory $reviewCollectionFactory,
        private readonly StorefrontScope $storefrontScope,
        private readonly StoreManagerInterface $storeManager,
        private readonly StoreTime $storeTime
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'review_list_for_product';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Returns approved customer reviews for a product by SKU: title, text, '
            . 'reviewer nickname, date and star rating (1-5), newest first. Anonymous '
            . 'callers get the reviews shown on the product page in this store.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sku' => [
                    'type' => 'string',
                    'description' => 'Exact SKU of the product.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of reviews to return (default 10, max 50).',
                ],
            ],
            'required' => ['sku'],
        ];
    }

    /**
     * Approved reviews are shown on the storefront — no ACL resource required.
     */
    public function getRequiredAclResource(): ?string
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        return $this->executeWithContext($arguments, null);
    }

    /**
     * @param array $arguments Expects `sku` (string, required) and optional `limit` (int).
     * @param AdminContext|null $adminContext The caller's resolved identity, or null for
     *     an anonymous request (no bearer token was supplied).
     * @return array{sku: string, reviews: array<int, array<string, mixed>>, count: int}
     */
    public function executeWithContext(array $arguments, ?AdminContext $adminContext): array
    {
        $adminView = $adminContext !== null && $adminContext->hasAclResource('Magento_Catalog::products');
        $sku = $arguments['sku'] ?? null;
        if (!is_string($sku) || trim($sku) === '') {
            throw new \InvalidArgumentException('Argument "sku" is required and must be a non-empty string.');
        }
        $sku = trim($sku);
        $limit = $this->limitArgument($arguments);

        try {
            $product = $this->productRepository->get($sku);
        } catch (NoSuchEntityException) {
            throw new \RuntimeException(sprintf('Product with SKU "%s" does not exist.', $sku));
        }

        if (!$adminView
            && !$this->storefrontScope->isProductVisible($product, StorefrontScope::VISIBLE_INDIVIDUALLY)
        ) {
            throw new \RuntimeException(sprintf('Product with SKU "%s" does not exist.', $sku));
        }

        $collection = $this->reviewCollectionFactory->create();
        $collection->addStatusFilter(Review::STATUS_APPROVED);
        $collection->addEntityFilter('product', (int)$product->getId());
        if (!$adminView) {
            $collection->addStoreFilter((int)$this->storeManager->getStore()->getId());
        }
        $collection->setDateOrder();
        $collection->setPageSize($limit);
        $collection->addRateVotes();

        $reviews = [];
        foreach ($collection as $review) {
            $reviews[] = [
                'title' => $review->getData('title'),
                'text' => $review->getData('detail'),
                'nickname' => $review->getData('nickname'),
                'created_at' => $this->storeTime->toLocal($review->getData('created_at')),
                'rating' => $this->averageRating($review->getData('rating_votes')),
            ];
        }

        return [
            'sku' => $sku,
            'reviews' => $reviews,
            'count' => count($reviews),
        ];
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
            $sum += (int)$vote->getData('value');
            $count++;
        }

        return $count > 0 ? round($sum / $count, 1) : null;
    }

    /**
     * Validates the optional "limit" argument.
     *
     * @param array $arguments Raw MCP tool call arguments.
     */
    private function limitArgument(array $arguments): int
    {
        if (!isset($arguments['limit'])) {
            return self::DEFAULT_LIMIT;
        }
        if (!is_numeric($arguments['limit']) || (int)$arguments['limit'] < 1) {
            throw new \InvalidArgumentException('Argument "limit" must be a positive integer.');
        }

        return min((int)$arguments['limit'], self::MAX_LIMIT);
    }
}
