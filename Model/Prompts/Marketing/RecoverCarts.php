<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Prompts\Marketing;

use Yu\McpServer\Model\PromptInterface;

/**
 * Abandoned-cart recovery workflow: pull the recent abandoned carts, triage them by value
 * and freshness, and draft a personalized recovery email for each cart worth chasing.
 * Drafts only — the module sends no email, the owner copies the text into their own
 * channel. The prompt only renders instructions; every tool it references is ACL-gated at
 * tools/call time as usual.
 */
class RecoverCarts implements PromptInterface
{
    private const DEFAULT_DAYS = '7';

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'recover_carts';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Abandoned-cart recovery: lists recent abandoned carts, picks the ones worth '
            . 'chasing (highest value first) and drafts a personalized recovery email per '
            . 'cart. Drafts only — nothing is sent automatically.';
    }

    /**
     * @inheritDoc
     */
    public function getArguments(): array
    {
        return [
            [
                'name' => 'days',
                'description' => 'How many days back to look for abandoned carts, 1-30. Default 7.',
                'required' => false,
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    public function render(array $arguments): array
    {
        $days = $arguments['days'] ?? self::DEFAULT_DAYS;
        if (!is_string($days) && !is_int($days)) {
            throw new \InvalidArgumentException('Argument "days" must be a number of days, 1-30.');
        }
        $days = trim((string) $days);
        if (!ctype_digit($days) || (int) $days < 1 || (int) $days > 30) {
            throw new \InvalidArgumentException('Argument "days" must be a number of days, 1-30.');
        }

        $text = <<<PROMPT
Run the abandoned-cart recovery workflow for the last {$days} day(s).

1. Gather the carts — cart_list_abandoned with days {$days}, min_age_hours 1 and a limit
   of 50. If there are none, say so and stop.
2. Triage. Order the carts by their total, highest first, and split them into:
   - "Chase now": totals well above the store's typical order value, or carts abandoned in
     the last 48 hours (freshest are the most recoverable).
   - "Batch reminder": everything else with a usable email address.
   - "Skip": carts without an email address, or with negligible totals — list them in one
     line for completeness.
3. Draft the recovery emails. For every "Chase now" cart write an individual email; for
   the "Batch reminder" group write one reusable template with placeholders. Each draft:
   - greets the customer by name when the cart has one, otherwise neutrally;
   - names the concrete products left in the cart (use the item names from the cart data);
   - keeps a short, friendly, no-pressure tone — a reminder plus an offer to help with
     questions (sizing, delivery), NOT a hard sell;
   - does not invent discounts or promo codes. If a nudge seems genuinely needed, add a
     separate note to the owner suggesting one — offering discounts is the owner's call.
4. Report format: a short summary table (cart, customer, total, age, category of action),
   then the drafts. Write customer-facing drafts in the store's language and everything
   addressed to the owner in the language the owner uses in this conversation.

Rules: nothing is sent by this workflow — every draft is for the owner to copy. Use only
data returned by the tools; never invent cart contents or customer details. Customer
emails are personal data — repeat them only where the draft needs an addressee.
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
