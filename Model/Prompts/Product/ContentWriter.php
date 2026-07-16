<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Prompts\Product;

use Yu\McpServer\Model\PromptInterface;

/**
 * Catalog copywriting workflow: find products with missing or empty descriptions/meta via
 * the catalog health report, draft the texts from what the product data actually says,
 * and apply them with product_update strictly one product at a time, each one only after
 * the owner approved that exact text. The confirmation gate exists because generated copy
 * lands on the public storefront — a human reads every word before customers do. The
 * prompt only renders instructions; every tool it references is ACL-gated at tools/call
 * time as usual.
 */
class ContentWriter implements PromptInterface
{
    private const DEFAULT_LIMIT = '5';

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'product_content_writer';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Fills catalog content gaps: finds products missing descriptions or meta '
            . 'descriptions, drafts the texts, and applies each one via product_update '
            . 'only after you approve that exact text. One product at a time.';
    }

    /**
     * @inheritDoc
     */
    public function getArguments(): array
    {
        return [
            [
                'name' => 'limit',
                'description' => 'How many products to draft content for in this session, '
                    . '1-10. Default 5.',
                'required' => false,
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    public function render(array $arguments): array
    {
        $limit = $arguments['limit'] ?? self::DEFAULT_LIMIT;
        if (!is_string($limit) && !is_int($limit)) {
            throw new \InvalidArgumentException('Argument "limit" must be a number of products, 1-10.');
        }
        $limit = trim((string)$limit);
        if (!ctype_digit($limit) || (int)$limit < 1 || (int)$limit > 10) {
            throw new \InvalidArgumentException('Argument "limit" must be a number of products, 1-10.');
        }

        $text = <<<PROMPT
Run a catalog content-writing session for up to {$limit} product(s).

1. Find the gaps — catalog_health_report with the default sample size. Take the products
   listed under "missing descriptions" and "missing meta descriptions"; prefer products
   that appear in both lists. If the report shows no content gaps, say so and stop.
2. For each candidate (up to {$limit}), load the full card with product_get by SKU. Base
   every draft ONLY on what the data shows: name, existing attributes, category, price
   tier. If the data is too thin to write anything truthful, skip the product and tell
   the owner what input is missing (e.g. "no attributes at all — describe it to me in one
   line and I'll draft from that").
3. Draft, per product:
   - description: 2-4 short paragraphs a customer would actually want to read — what the
     product is, what it is good for, and any concrete attributes worth calling out. No
     invented facts, no superlatives that the data cannot back ("best", "premium") and no
     keyword stuffing.
   - short_description: 1-2 sentences for listing pages.
   - Note: meta descriptions are NOT editable via product_update — when only the meta is
     missing, still draft it (150-160 characters, plain language, names the product) and
     hand it to the owner to paste into the admin panel; say so explicitly.
4. Present each product one at a time: SKU, name, current state, the drafted texts. Then
   WAIT for the owner's verdict on THAT product. Apply with product_update (fields
   description and/or short_description) only what the owner approved — if they edited
   your draft, apply their version. Never batch several products into one confirmation.
5. Close the session with a list of what was applied (SKU, fields changed) and what
   remains for another run.

Rules: write storefront texts in the store's language; talk to the owner in the language
they use in this conversation. Do not touch price, status or any other product field —
this session edits text content only. If product_update fails with an authorization
error, name the missing permission and hand the drafts over instead.
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
