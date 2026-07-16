<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Prompts\Product;

use Yu\McpServer\Model\PromptInterface;

/**
 * Restock planning workflow: combine current low-stock levels with each product's recent
 * sales velocity into a ranked purchase plan — what runs out first, and how much to
 * reorder to cover the target horizon. Diagnose-only: the prompt forbids touching stock
 * via write tools; the numbers go to the owner's supplier order, not back into Magento.
 * The prompt only renders instructions; every tool it references is ACL-gated at
 * tools/call time as usual.
 */
class RestockAdvisor implements PromptInterface
{
    private const DEFAULT_HORIZON_DAYS = '30';

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'restock_advisor';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Restock plan: ranks low-stock products by estimated days until stockout '
            . '(current qty vs recent sales velocity) and calculates how much to reorder '
            . 'to cover the chosen horizon. Read-only — produces a purchase list for the '
            . 'supplier, changes nothing in Magento.';
    }

    /**
     * @inheritDoc
     */
    public function getArguments(): array
    {
        return [
            [
                'name' => 'horizon_days',
                'description' => 'Reorder horizon in days the new stock should cover, 7-90. '
                    . 'Default 30.',
                'required' => false,
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    public function render(array $arguments): array
    {
        $horizon = $arguments['horizon_days'] ?? self::DEFAULT_HORIZON_DAYS;
        if (!is_string($horizon) && !is_int($horizon)) {
            throw new \InvalidArgumentException('Argument "horizon_days" must be a number of days, 7-90.');
        }
        $horizon = trim((string)$horizon);
        if (!ctype_digit($horizon) || (int)$horizon < 7 || (int)$horizon > 90) {
            throw new \InvalidArgumentException('Argument "horizon_days" must be a number of days, 7-90.');
        }

        $text = <<<PROMPT
Build a restock plan. Target horizon: the new stock should cover {$horizon} day(s) of
sales.

1. Candidates — product_low_stock with the default threshold (limit 50). If nothing is
   low on stock, say so and stop.
2. Velocity — for each low-stock SKU call product_sales_velocity with days 30. Note both
   the daily sales pace and the tool's own stockout forecast.
3. Rank and calculate. For every SKU derive:
   - days until stockout = current qty / daily pace (use the tool's forecast when it
     provides one; if the SKU had no sales in the window, mark it "no recent sales"
     instead of a number);
   - suggested reorder qty = daily pace x {$horizon}, rounded UP to a whole unit, minus
     what is still on hand. Never suggest a negative quantity.
   Sort by days until stockout, soonest first.
4. Report format:
   - A purchase table: SKU, product name, qty on hand, daily pace, days until stockout,
     suggested reorder qty. This table is the deliverable — the owner sends it to the
     supplier.
   - After the table: "no recent sales" SKUs as a separate short list — restocking those
     is a judgment call, not arithmetic; say why (seasonality? never sold? new product?).
   - A 2-3 line summary on top: how many SKUs are urgent (under 7 days of cover) and
     which single SKU runs out first.

Rules: read-only — do NOT call product_update_stock or any other write tool; stock numbers
in Magento change only when physical goods arrive, and that is the owner's action. Use
only numbers returned by the tools — never estimate missing figures. If a tool fails with
an authorization error, name the missing permission and continue with what you have.
Write the report in the language the owner uses in this conversation.
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
