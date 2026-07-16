<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Tools\Marketing;

use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Quote\Model\ResourceModel\Quote\CollectionFactory as QuoteCollectionFactory;
use Magento\Quote\Model\ResourceModel\Quote\Item\CollectionFactory as QuoteItemCollectionFactory;
use Yu\McpServer\Model\ToolInterface;

/**
 * Abandoned-cart report: active quotes with items and a known customer email that stopped
 * changing a while ago. Mirrors the admin "Abandoned Carts" report (hence the ACL resource)
 * — recovery outreach is the whole point, so customer contact data is included by design.
 */
class GetAbandonedCarts implements ToolInterface
{
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 50;
    private const DEFAULT_DAYS = 14;
    private const MAX_DAYS = 90;
    private const DEFAULT_MIN_AGE_HOURS = 1;
    private const MAX_MIN_AGE_HOURS = 720;

    public function __construct(
        private readonly QuoteCollectionFactory $quoteCollectionFactory,
        private readonly QuoteItemCollectionFactory $quoteItemCollectionFactory,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'cart_list_abandoned';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Lists abandoned shopping carts: active carts with items and a known customer '
            . 'email, untouched for at least min_age_hours (default 1) within the last "days" '
            . 'days (default 14), most recently abandoned first. Returns customer name/email, '
            . 'cart total, currency, last-activity date and the cart items (SKU, name, qty, '
            . 'price). Guest carts without an email cannot be listed. Typical use: recovery '
            . 'outreach — "abandoned carts this week worth over 1000".';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'days' => [
                    'type' => 'integer',
                    'description' => 'Look-back window in days (default 14, max 90).',
                ],
                'min_age_hours' => [
                    'type' => 'integer',
                    'description' => 'Only carts untouched for at least this many hours '
                        . '(default 1, max 720) — a cart changed minutes ago is not '
                        . 'abandoned yet.',
                ],
                'min_total' => [
                    'type' => 'number',
                    'description' => 'Only carts with a grand total >= this value.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Carts per page (default 20, max 50).',
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
     * Same admin permission as the built-in Reports > Abandoned Carts grid — the tool
     * exposes customer contact data, which is the report's purpose.
     */
    public function getRequiredAclResource(): ?string
    {
        return 'Magento_Reports::abandoned';
    }

    /**
     * @param array $arguments See getInputSchema().
     * @return array{carts: array<int, array<string, mixed>>, count: int, total: int, page: int}
     */
    public function execute(array $arguments): array
    {
        $days = $this->boundedIntArgument($arguments, 'days', self::DEFAULT_DAYS, 1, self::MAX_DAYS);
        $minAgeHours = $this->boundedIntArgument(
            $arguments,
            'min_age_hours',
            self::DEFAULT_MIN_AGE_HOURS,
            0,
            self::MAX_MIN_AGE_HOURS
        );
        $minTotal = $this->minTotalArgument($arguments);
        $limit = $this->boundedIntArgument($arguments, 'limit', self::DEFAULT_LIMIT, 1, self::MAX_LIMIT);
        $page = $this->boundedIntArgument($arguments, 'page', 1, 1, PHP_INT_MAX);

        $now = $this->dateTime->gmtTimestamp();
        $updatedFrom = date('Y-m-d H:i:s', $now - $days * 86400);
        $updatedTo = date('Y-m-d H:i:s', $now - $minAgeHours * 3600);

        $collection = $this->quoteCollectionFactory->create();
        $collection->addFieldToFilter('is_active', 1);
        $collection->addFieldToFilter('items_count', ['gt' => 0]);
        $collection->addFieldToFilter('customer_email', ['notnull' => true]);
        $collection->addFieldToFilter('updated_at', ['gteq' => $updatedFrom]);
        $collection->addFieldToFilter('updated_at', ['lteq' => $updatedTo]);
        if ($minTotal !== null) {
            $collection->addFieldToFilter('grand_total', ['gteq' => $minTotal]);
        }
        $collection->setOrder('updated_at', 'DESC');
        $collection->setPageSize($limit);
        $collection->setCurPage($page);

        $total = $collection->getSize();

        $carts = [];
        $quoteIds = [];
        foreach ($collection as $quote) {
            $quoteId = (int)$quote->getId();
            $quoteIds[] = $quoteId;
            $carts[$quoteId] = [
                'customer_name' => trim(
                    ($quote->getData('customer_firstname') ?? '') . ' ' . ($quote->getData('customer_lastname') ?? '')
                ) ?: null,
                'customer_email' => $quote->getData('customer_email'),
                'is_guest' => (bool)$quote->getData('customer_is_guest'),
                'created_at' => $quote->getData('created_at'),
                'last_activity_at' => $quote->getData('updated_at'),
                'grand_total' => (float)$quote->getData('grand_total'),
                'currency' => $quote->getData('quote_currency_code'),
                'items' => [],
            ];
        }

        foreach ($this->loadItems($quoteIds) as $quoteId => $items) {
            $carts[$quoteId]['items'] = $items;
        }

        return [
            'carts' => array_values($carts),
            'count' => count($carts),
            'total' => $total,
            'page' => $page,
        ];
    }

    /**
     * Batch-loads the visible items of the page's quotes (one query for the whole page),
     * keyed by quote id. Child rows of configurable/bundle items are skipped — the parent
     * row carries the customer-facing name, quantity and price.
     *
     * @param int[] $quoteIds
     * @return array<int, array<int, array{sku: string, name: string, qty: float, price: float, row_total: float}>>
     */
    private function loadItems(array $quoteIds): array
    {
        if ($quoteIds === []) {
            return [];
        }

        $collection = $this->quoteItemCollectionFactory->create();
        $collection->addFieldToFilter('quote_id', ['in' => $quoteIds]);
        $collection->addFieldToFilter('parent_item_id', ['null' => true]);

        $itemsByQuote = [];
        foreach ($collection as $item) {
            $itemsByQuote[(int)$item->getData('quote_id')][] = [
                'sku' => (string)$item->getData('sku'),
                'name' => (string)$item->getData('name'),
                'qty' => (float)$item->getData('qty'),
                'price' => (float)$item->getData('price'),
                'row_total' => (float)$item->getData('row_total'),
            ];
        }

        return $itemsByQuote;
    }

    /**
     * Validates an optional integer argument against a minimum, applying a default and a cap.
     */
    private function boundedIntArgument(array $arguments, string $key, int $default, int $min, int $max): int
    {
        if (!isset($arguments[$key])) {
            return $default;
        }
        if (!is_numeric($arguments[$key]) || (int)$arguments[$key] < $min) {
            throw new \InvalidArgumentException(
                sprintf('Argument "%s" must be an integer >= %d.', $key, $min)
            );
        }

        return min((int)$arguments[$key], $max);
    }

    /**
     * Validates the optional "min_total" argument.
     */
    private function minTotalArgument(array $arguments): ?float
    {
        if (!isset($arguments['min_total'])) {
            return null;
        }
        if (!is_numeric($arguments['min_total']) || (float)$arguments['min_total'] < 0) {
            throw new \InvalidArgumentException('Argument "min_total" must be a number >= 0.');
        }

        return (float)$arguments['min_total'];
    }
}
