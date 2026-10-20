<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Tools\Marketing;

use Magento\Customer\Api\Data\GroupInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\SalesRule\Model\ResourceModel\Rule\CollectionFactory;
use Magento\SalesRule\Model\Rule;
use Magento\Store\Model\StoreManagerInterface;
use Yu\McpServer\Model\Auth\AdminContext;
use Yu\McpServer\Model\ContextAwareToolInterface;

/**
 * Lists currently active cart price rules. Coupon CODES are deliberately never exposed:
 * whether a code has been published is a marketing decision, not something an anonymous
 * API should decide. Only the fact that a coupon is required is reported. That guarantee
 * also covers the rule's own name/description text — merchants routinely paste the code
 * there ("Use promo code X at checkout"), so occurrences of the rule's coupon code are
 * redacted from every text field before it leaves the tool.
 *
 * Two storefronts (see ContextAwareToolInterface): anonymous callers get what a guest of
 * the current website is offered — rules for the NOT LOGGED IN group on this website,
 * described by their storefront label. The rule name and description are admin-only:
 * they are internal notes ("Wholesale partners -40%") rather than customer-facing text.
 * An admin with the cart price rule ACL sees every active rule with its internal texts.
 */
class ListActivePromotions implements ContextAwareToolInterface
{
    private const ADMIN_RESOURCE = 'Magento_SalesRule::quote';
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 50;

    public function __construct(
        private readonly CollectionFactory $ruleCollectionFactory,
        private readonly TimezoneInterface $timezone,
        private readonly StoreManagerInterface $storeManager
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
        return 'Lists currently active promotions (cart price rules): storefront label, '
            . 'validity dates, discount action and whether a coupon code is required. '
            . 'Anonymous callers get the promotions a guest of this store is offered; an '
            . 'authenticated admin with cart price rule permission gets every active rule '
            . 'with its internal name and description. Coupon codes themselves are never '
            . 'returned.';
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
     * The guest-facing promotions are public marketing content — no ACL resource required.
     * The admin extras are gated inside executeWithContext().
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
     * @param array $arguments Optional `limit` (int).
     * @param AdminContext|null $adminContext The caller's resolved identity, or null for
     *     an anonymous request (no bearer token was supplied).
     * @return array{promotions: array<int, array<string, mixed>>, count: int}
     */
    public function executeWithContext(array $arguments, ?AdminContext $adminContext): array
    {
        $limit = $this->limitArgument($arguments);
        $today = $this->timezone->date()->format('Y-m-d');
        $adminView = $adminContext !== null && $adminContext->hasAclResource(self::ADMIN_RESOURCE);
        $store = $this->storeManager->getStore();

        $collection = $this->ruleCollectionFactory->create();
        if ($adminView) {
            $collection->addFieldToFilter('is_active', 1);
            $collection->addFieldToFilter('from_date', [['null' => true], ['lteq' => $today]]);
            $collection->addFieldToFilter('to_date', [['null' => true], ['gteq' => $today]]);
        } else {
            $collection->addWebsiteGroupDateFilter(
                (int)$store->getWebsiteId(),
                GroupInterface::NOT_LOGGED_IN_ID,
                $today
            );
        }
        $collection->setPageSize($limit);

        $promotions = [];
        foreach ($collection as $rule) {
            $couponRequired = (int)$rule->getData('coupon_type') !== Rule::COUPON_TYPE_NO_COUPON;
            $couponCode = $couponRequired ? $this->couponCode($rule) : null;
            $label = (string)$rule->getStoreLabel($store);

            $promotion = [
                'label' => $label !== '' ? $this->redactCouponCode($label, $couponCode) : null,
            ];
            if ($adminView) {
                $promotion['name'] = $this->redactCouponCode((string)$rule->getData('name'), $couponCode);
                $promotion['description'] = $this->redactCouponCode(
                    (string)$rule->getData('description'),
                    $couponCode
                );
            }
            $promotions[] = $promotion + [
                'from_date' => $rule->getData('from_date'),
                'to_date' => $rule->getData('to_date'),
                'action' => $rule->getData('simple_action'),
                'discount_amount' => (float)$rule->getData('discount_amount'),
                'coupon_required' => $couponRequired,
            ];
        }

        return [
            'promotions' => $promotions,
            'count' => count($promotions),
        ];
    }

    /**
     * The rule's primary coupon code, or null when none is set.
     *
     * @param Rule $rule Cart price rule to read the coupon from.
     */
    private function couponCode(Rule $rule): ?string
    {
        $code = $rule->getPrimaryCoupon() ? (string)$rule->getPrimaryCoupon()->getCode() : '';

        return $code === '' ? null : $code;
    }

    /**
     * Removes every occurrence of the rule's coupon code (case-insensitive) from
     * merchant-authored text, so the code can't leak through name/description.
     *
     * @param string $text Merchant-authored text to redact.
     * @param string|null $couponCode Coupon code to remove, or null when the rule has none.
     */
    private function redactCouponCode(string $text, ?string $couponCode): string
    {
        if ($couponCode === null || $text === '') {
            return $text;
        }

        return trim(str_ireplace($couponCode, '[coupon code hidden]', $text));
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
