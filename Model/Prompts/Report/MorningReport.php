<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Prompts\Report;

use Yu\McpServer\Model\PromptInterface;

/**
 * The store owner's daily digest: one prompt that walks the model through the five
 * operational questions of the morning — sales, stuck orders, review moderation queue,
 * abandoned carts and low stock. The prompt only renders instructions; every tool it
 * references is ACL-gated at tools/call time as usual.
 */
class MorningReport implements PromptInterface
{
    private const DEFAULT_PERIOD = 'yesterday';

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'morning_report';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Owner\'s morning digest: sales summary for the period, orders stuck in '
            . 'processing, pending reviews with text-based sentiment analysis, fresh '
            . 'abandoned carts and products running low on stock.';
    }

    /**
     * @inheritDoc
     */
    public function getArguments(): array
    {
        return [
            [
                'name' => 'period',
                'description' => 'Period to report on: "yesterday" (default), "today", '
                    . '"last 7 days" or an explicit range like "2026-07-01 to 2026-07-07".',
                'required' => false,
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    public function render(array $arguments): array
    {
        $period = $arguments['period'] ?? self::DEFAULT_PERIOD;
        if (!is_string($period) || trim($period) === '') {
            throw new \InvalidArgumentException('Argument "period" must be a non-empty string.');
        }
        $period = trim($period);

        $text = <<<PROMPT
Prepare the store owner's morning report for the period: {$period}.

Resolve the period into concrete UTC dates first ("yesterday" = the full previous calendar
day, "today" = from midnight UTC until now). Then gather the data with the store's tools:

1. Sales — sales_summary with created_from/created_to for the period and top_products 5.
2. Orders needing action — order_list with status "processing" (limit 20, no date filter):
   these wait for fulfilment regardless of when they were placed. Flag any older than
   3 days as overdue.
3. Review moderation queue — review_list with status "pending" (limit 50). Judge each
   review's sentiment by its TEXT, not its star rating — ratings are optional and often
   missing or misleading. Group negative reviews by the underlying problem (sizing,
   quality, delivery, product description...). Recommend approve/reject per review, but do
   NOT call review_moderate — moderation is applied only after the owner explicitly
   confirms.
4. Abandoned carts — cart_list_abandoned with min_age_hours 1 and days matching the period
   (at least 1). Point out the carts most worth a recovery email (highest totals first).
5. Stock — product_low_stock with the default threshold.

Report format:
- Start with a 3-5 line executive summary: revenue, order count, and the single most
  urgent action for today.
- Then short sections in this order: Sales, Orders needing action, Reviews, Abandoned
  carts, Low stock. Skip a section entirely if it has nothing actionable and say so in
  one line.
- Use only numbers returned by the tools — never estimate or invent figures. If a tool
  fails with an authorization error, name the missing permission and continue with the
  remaining sections.
- Write the report in the language the owner uses in this conversation.
PROMPT;

        return [
            [
                'role' => 'user',
                'content' => [
                    'type' => 'text',
                    'text' => $text,
                ],
            ],
        ];
    }
}
