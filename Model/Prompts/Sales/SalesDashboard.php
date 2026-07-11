<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Prompts\Sales;

use Yu\McpServer\Model\PromptInterface;

/**
 * Visual sales analytics for a period: the model gathers headline numbers, a bucketed
 * trend, category/bestseller breakdowns and the payment/shipping mix, then renders them
 * as ONE dashboard with charts (an interactive artifact when the client supports it,
 * markdown tables otherwise). Read-only by nature — every referenced tool is a sales
 * report. The prompt only renders instructions; every tool it references is ACL-gated
 * at tools/call time as usual.
 */
class SalesDashboard implements PromptInterface
{
    private const DEFAULT_PERIOD = 'last 30 days';

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'sales_dashboard';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Interactive sales dashboard for a period: revenue/orders/average-order-value '
            . 'headline, a trend chart bucketed by day/week/month, revenue by category, '
            . 'bestsellers, payment and shipping mix — rendered as charts with a short '
            . 'takeaway summary.';
    }

    /**
     * @inheritDoc
     */
    public function getArguments(): array
    {
        return [
            [
                'name' => 'period',
                'description' => 'Period to visualise: "last 30 days" (default), "last 7 days", '
                    . '"this month" or an explicit range like "2026-06-01 to 2026-06-30".',
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
Build a sales analytics dashboard for the period: {$period}.

Resolve the period into concrete UTC dates first (e.g. "last 30 days" = the 30 full
calendar days ending yesterday inclusive). Then gather the data with the store's tools:

1. Headline numbers — sales_summary for the whole period with top_products 5.
2. Trend — split the period into buckets and call sales_summary once per bucket with
   that bucket's created_from/created_to: daily buckets for periods up to 14 days,
   weekly buckets up to about 4 months, calendar months beyond that. Keep it to at most
   15 bucket calls — choose a coarser granularity rather than exceeding that.
3. Categories — sales_by_category for the whole period (limit 10).
4. Bestsellers — sales_bestsellers with sort_by "revenue" and limit 10.
5. Payment and shipping mix — sales_payment_stats and sales_shipping_stats for the
   whole period.

Presentation — this report is about charts, not prose:
- If this client can render artifacts (interactive HTML), build ONE self-contained
  dashboard page: a KPI row (revenue, order count, average order value), a trend chart
  of revenue and orders per bucket, a bar chart of revenue by category, the bestsellers
  ranked by revenue, and charts for the payment and shipping split. Charts must be
  readable at a glance: labeled axes, the currency stated, exact values visible on
  hover or beside the bars.
- The artifact must work without loading ANYTHING external: no Chart.js or other chart
  libraries from a CDN and no external fonts/styles — artifact sandboxes block such
  requests and the page dies with errors like "Chart is not defined". Draw the charts
  with inline SVG or hand-written <canvas> code; in a React artifact the built-in
  recharts library is also fine.
- If artifacts are not available in this client, present the same content in the same
  order as compact markdown tables instead.
- Below the dashboard add 3-5 lines of takeaways: the trend direction, the strongest
  category, and anything unusual worth the owner's attention.

Rules:
- Every plotted number must come from a tool response — never estimate, interpolate or
  invent data points, and never fill an empty bucket with anything but zero orders as
  reported by the tool.
- If the store sells in several currencies, keep each currency a separate series or
  row — never add amounts across currencies.
- If a tool fails with an authorization error, name the missing permission, drop that
  panel and continue with the rest.
- Write all chart labels, takeaways and surrounding text in the language the owner uses
  in this conversation.
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
