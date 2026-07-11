<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Tools\Marketing;

use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\SalesRule\Model\ResourceModel\Rule\CollectionFactory;
use Magento\SalesRule\Model\Rule;
use Yu\McpServer\Model\ToolInterface;

/**
 * Lists currently active cart price rules. Coupon CODES are deliberately never exposed:
 * whether a code has been published is a marketing decision, not something an anonymous
 * API should decide. Only the fact that a coupon is required is reported.
 */
class ListActivePromotions implements ToolInterface
{
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 50;

    public function __construct(
        private readonly CollectionFactory $ruleCollectionFactory,
        private readonly TimezoneInterface $timezone
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'promotion_list';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Lists currently active promotions (cart price rules): name, description, '
            . 'validity dates, discount action and whether a coupon code is required. '
            . 'Coupon codes themselves are never returned.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of promotions to return (default 20, max 50).',
                ],
            ],
            'required' => [],
        ];
    }

    /**
     * Active promotions are public marketing content — no ACL resource required.
     */
    public function getRequiredAclResource(): ?string
    {
        return null;
    }

    /**
     * @param array $arguments Optional `limit` (int).
     * @return array{promotions: array<int, array<string, mixed>>, count: int}
     */
    public function execute(array $arguments): array
    {
        $limit = $this->limitArgument($arguments);
        $today = $this->timezone->date()->format('Y-m-d');

        $collection = $this->ruleCollectionFactory->create();
        $collection->addFieldToFilter('is_active', 1);
        $collection->addFieldToFilter('from_date', [['null' => true], ['lteq' => $today]]);
        $collection->addFieldToFilter('to_date', [['null' => true], ['gteq' => $today]]);
        $collection->setPageSize($limit);

        $promotions = [];
        foreach ($collection as $rule) {
            $promotions[] = [
                'name' => $rule->getData('name'),
                'description' => $rule->getData('description'),
                'from_date' => $rule->getData('from_date'),
                'to_date' => $rule->getData('to_date'),
                'action' => $rule->getData('simple_action'),
                'discount_amount' => (float) $rule->getData('discount_amount'),
                'coupon_required' => (int) $rule->getData('coupon_type') !== Rule::COUPON_TYPE_NO_COUPON,
            ];
        }

        return [
            'promotions' => $promotions,
            'count' => count($promotions),
        ];
    }

    /**
     * Validates the optional "limit" argument.
     */
    private function limitArgument(array $arguments): int
    {
        if (!isset($arguments['limit'])) {
            return self::DEFAULT_LIMIT;
        }
        if (!is_numeric($arguments['limit']) || (int) $arguments['limit'] < 1) {
            throw new \InvalidArgumentException('Argument "limit" must be a positive integer.');
        }

        return min((int) $arguments['limit'], self::MAX_LIMIT);
    }
}
