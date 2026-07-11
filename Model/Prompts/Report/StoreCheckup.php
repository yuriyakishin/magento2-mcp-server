<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Prompts\Report;

use Yu\McpServer\Model\PromptInterface;

/**
 * The store's periodic health inspection: one prompt that walks the model through the
 * technical state (system_health), catalog content quality (catalog_health_report),
 * unmet search demand (search_terms_report), the moderation queue and restock risks —
 * and asks for a prioritized action plan. Complements morning_report: that one answers
 * "what happened during the period?", this one answers "what shape is the store in?".
 * The prompt only renders instructions; every tool it references is ACL-gated at
 * tools/call time as usual.
 */
class StoreCheckup implements PromptInterface
{
    private const DEFAULT_PERIOD = 'last 30 days';

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'store_checkup';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Full store health check: technical state (indexers, cron, cache), catalog '
            . 'content quality, search demand the catalog does not cover, pending reviews '
            . 'and restock risks — summarized into a prioritized action plan. Run it weekly '
            . 'or after big changes; for the daily operational digest use morning_report.';
    }

    /**
     * @inheritDoc
     */
    public function getArguments(): array
    {
        return [
            [
                'name' => 'period',
                'description' => 'Period for the sales-dependent checks (search demand, '
                    . 'sales velocity): "last 30 days" (default), "last 7 days" or an '
                    . 'explicit range like "2026-06-01 to 2026-06-30".',
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
Run a full health check of the store. Period for the sales-dependent checks: {$period}
(resolve it into concrete UTC dates first).

Gather the data with the store's tools, in this order:

1. Technical state — system_health. Anything "invalid" among indexers, failed or stuck
   cron jobs, disabled or invalidated caches is a finding; explain in one line what each
   one means for the storefront (stale prices, missing emails, slow pages...).
2. Catalog content — catalog_health_report with the default sample size. For each check
   (missing images, missing descriptions, missing meta descriptions, zero prices) report
   the count and, when it is above zero, name the sample SKUs.
3. Unmet demand — search_terms_report. Look at the zero-result terms with the highest hit
   counts: which of them could be covered by existing products (a synonym/naming problem)
   and which point at genuinely missing assortment?
4. Reviews waiting — review_list with status "pending" (limit 50). Count them and judge
   sentiment by the review TEXT, not the star rating. Recommend approve/reject per review,
   but do NOT call review_moderate — moderation is applied only after the owner explicitly
   confirms.
5. Restock risks — product_low_stock with the default threshold, then
   product_sales_velocity for each low-stock SKU over the period. Rank by estimated days
   until stockout, soonest first.

Report format:
- Start with an overall verdict in 2-3 lines: is the store healthy, and what is the single
  most important thing to fix?
- Then a prioritized action plan in three buckets: "Fix now" (breaks selling or the
  storefront), "This week" (loses money or customers slowly), "Backlog" (cosmetic).
  Every item names the concrete next step and the tool or admin action that performs it
  (e.g. "fill the meta description via product_update", "reindex from the admin/CLI").
- Diagnose only — do not call any write tool during the checkup. Propose write actions
  for the owner to confirm instead.
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
