<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Prompts\Sales;

use Yu\McpServer\Model\PromptInterface;

/**
 * Two-period comparison rendered visually: sales_compare_periods supplies both periods'
 * per-currency orders/revenue/average-order-value plus server-side percentage deltas,
 * bestseller lists for each period expose which products moved — and the model turns
 * that into delta cards and a current-vs-baseline chart. The prompt only renders
 * instructions; every tool it references is ACL-gated at tools/call time as usual.
 */
class SalesTrends implements PromptInterface
{
    private const DEFAULT_PERIOD = 'last 30 days';

    private const DEFAULT_BASELINE = 'the period of the same length immediately before';

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'sales_trends';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Visual comparison of two sales periods: current vs baseline KPI cards with '
            . 'percentage deltas (computed server-side by sales_compare_periods), a '
            . 'current-vs-baseline chart per currency, and the bestseller movers between '
            . 'the two periods.';
    }

    /**
     * @inheritDoc
     */
    public function getArguments(): array
    {
        return [
            [
                'name' => 'period',
                'description' => 'Current period to analyse: "last 30 days" (default), '
                    . '"this month" or an explicit range like "2026-06-01 to 2026-06-30".',
                'required' => false,
            ],
            [
                'name' => 'compare_to',
                'description' => 'Baseline period: defaults to the period of the same length '
                    . 'immediately before the current one; "same period last year" or an '
                    . 'explicit range also work.',
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

        $baseline = $arguments['compare_to'] ?? self::DEFAULT_BASELINE;
        if (!is_string($baseline) || trim($baseline) === '') {
            throw new \InvalidArgumentException('Argument "compare_to" must be a non-empty string.');
        }
        $baseline = trim($baseline);

        $text = <<<PROMPT
Compare the store's sales between two periods and visualise the change.
Current period: {$period}. Baseline: {$baseline}.

1. Resolve both periods into concrete UTC date ranges first. The two ranges must not
   overlap; when the baseline is relative ("immediately before", "last year"), derive it
   from the resolved current period.
2. Core comparison — sales_compare_periods with period_a_from/period_a_to = the baseline
   and period_b_from/period_b_to = the current period (omit period_b_to when the current
   period runs up to now). The tool returns per-currency orders, revenue and average
   order value for both periods plus percentage changes computed server-side — quote
   those percentages as returned, do not recompute them yourself.
3. Movers — sales_bestsellers with sort_by "revenue" and limit 10 for EACH of the two
   periods. From the two lists identify products that climbed, products that fell, new
   entries and products that dropped out of the top.

Presentation — this report is about charts, not prose:
- If this client can render artifacts (interactive HTML), build ONE self-contained
  page: KPI cards for orders, revenue and average order value (current value plus a
  clearly colored up/down delta badge vs the baseline, one card set per currency), a
  grouped bar chart of current vs baseline per metric, and the movers as a table with
  rise/fall markers.
- The artifact must work without loading ANYTHING external: no Chart.js or other chart
  libraries from a CDN and no external fonts/styles — artifact sandboxes block such
  requests and the page dies with errors like "Chart is not defined". Draw the charts
  with inline SVG or hand-written <canvas> code; in a React artifact the built-in
  recharts library is also fine.
- If artifacts are not available in this client, present the same content as compact
  markdown tables instead.
- Finish with a 3-5 line verdict: is the store growing or shrinking on this comparison,
  and which products or metrics drive the change.

Rules:
- Every plotted number must come from a tool response — never estimate or invent data
  points.
- If the store sells in several currencies, keep each currency a separate card set or
  series — never add amounts across currencies.
- If a tool fails with an authorization error, name the missing permission and continue
  with what you have.
- Write all chart labels, verdicts and surrounding text in the language the owner uses
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
