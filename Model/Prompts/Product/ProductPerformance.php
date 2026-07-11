<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Prompts\Product;

use Yu\McpServer\Model\PromptInterface;

/**
 * Product-level analytics with charts: the period's top sellers by revenue AND by
 * quantity (the mismatches between the two lists are the interesting products), each
 * enriched with its sales velocity and stockout forecast — a bestseller about to run
 * out is the most urgent finding this report can produce. Read-only: restocking is the
 * owner's decision (restock_advisor exists for the purchase plan). The prompt only
 * renders instructions; every tool it references is ACL-gated at tools/call time as
 * usual.
 */
class ProductPerformance implements PromptInterface
{
    private const DEFAULT_PERIOD = 'last 30 days';

    private const DEFAULT_TOP = '10';

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'product_performance';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Product performance charts for a period: top products by revenue and by '
            . 'quantity sold, each with its current sales velocity and stockout forecast; '
            . 'highlights bestsellers at risk of running out. Read-only — changes nothing '
            . 'in Magento.';
    }

    /**
     * @inheritDoc
     */
    public function getArguments(): array
    {
        return [
            [
                'name' => 'period',
                'description' => 'Sales period to analyse: "last 30 days" (default), '
                    . '"last 7 days" or an explicit range like "2026-06-01 to 2026-06-30".',
                'required' => false,
            ],
            [
                'name' => 'top',
                'description' => 'How many top products to chart per ranking, 1-15. Default 10.',
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

        $top = $arguments['top'] ?? self::DEFAULT_TOP;
        if (!is_string($top) && !is_int($top)) {
            throw new \InvalidArgumentException('Argument "top" must be a number of products, 1-15.');
        }
        $top = trim((string) $top);
        if (!ctype_digit($top) || (int) $top < 1 || (int) $top > 15) {
            throw new \InvalidArgumentException('Argument "top" must be a number of products, 1-15.');
        }

        $text = <<<PROMPT
Analyse product performance for the period: {$period}, focusing on the top {$top}
product(s) per ranking.

1. Resolve the period into concrete UTC dates first (e.g. "last 30 days" = the 30 full
   calendar days ending yesterday inclusive).
2. Top sellers — call sales_bestsellers for the period twice: once with sort_by
   "revenue" and once with sort_by "qty", limit {$top} each. Merge the two lists into
   one set of SKUs — products high on quantity but low on revenue (and the reverse) are
   exactly the ones worth a closer look.
3. Velocity and stock cover — for each SKU in the merged set call
   product_sales_velocity with days 30: current daily pace, stock on hand and the
   tool's own stockout forecast.

Presentation — this report is about charts, not prose:
- If this client can render artifacts (interactive HTML), build ONE self-contained
  page: a horizontal bar chart of revenue per product with the quantity sold shown
  alongside, and a stock-cover view plotting units per day against estimated days left.
  Visually highlight every SKU with under 14 days of cover — a bestseller about to run
  out is the single most urgent finding of this report. State the currency on every
  money axis.
- The artifact must work without loading ANYTHING external: no Chart.js or other chart
  libraries from a CDN and no external fonts/styles — artifact sandboxes block such
  requests and the page dies with errors like "Chart is not defined". Draw the charts
  with inline SVG or hand-written <canvas> code; in a React artifact the built-in
  recharts library is also fine.
- Always include the full table: SKU, product name, qty sold, revenue, units per day,
  stock on hand, estimated days left ("no recent sales" where the tool gives no
  forecast).
- If artifacts are not available in this client, present the charts' content as compact
  markdown tables instead.
- Finish with 3-5 lines of takeaways: which products earn the money, which are at
  stockout risk, and which sell in volume but bring little revenue.

Rules: read-only — do NOT call product_update_stock or any other write tool; restocking
is the owner's decision, and the restock_advisor prompt exists for the purchase plan.
Every plotted number must come from a tool response — never estimate or invent data
points, and never add amounts across currencies. If a tool fails with an authorization
error, name the missing permission and continue with what you have. Write all chart
labels, takeaways and surrounding text in the language the owner uses in this
conversation.
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
