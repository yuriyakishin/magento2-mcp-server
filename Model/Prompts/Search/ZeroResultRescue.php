<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Prompts\Search;

use Yu\McpServer\Model\PromptInterface;

/**
 * Unmet-demand analysis: take the storefront search terms that returned zero results,
 * check whether the catalog actually covers them under different names, and classify each
 * term as a naming problem, an assortment gap or noise — with a concrete suggested fix
 * per term. Diagnose-only: renaming products, adding synonyms or extending the assortment
 * are owner actions; the prompt produces the worklist. The prompt only renders
 * instructions; every tool it references is ACL-gated at tools/call time as usual.
 */
class ZeroResultRescue implements PromptInterface
{
    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'zero_result_rescue';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Analyzes storefront searches that returned nothing: checks whether the '
            . 'catalog covers them under different names and classifies each term as a '
            . 'naming problem, an assortment gap or noise — with a suggested fix per term. '
            . 'Read-only.';
    }

    /**
     * @inheritDoc
     */
    public function getArguments(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function render(array $arguments): array
    {
        $text = <<<PROMPT
Analyze the storefront searches that found nothing, and turn them into a worklist.

1. Get the data — search_terms_report with the default limit. Focus on the zero-result
   terms, most-searched first. If there are none, congratulate the owner and stop.
2. Investigate each zero-result term (top 15 at most):
   - Probe the catalog with product_search for the term itself AND for likely synonyms,
     translations or spelling fixes of it (e.g. a Cyrillic term for a Latin-named product,
     singular/plural, common misspellings).
   - Classify:
     a) "naming gap" — matching products exist but under different words. Name the SKUs
        and the exact word that should be added to the product name or description so the
        storefront search finds it.
     b) "assortment gap" — customers repeatedly ask for something the store does not
        sell. Report the demand (hit count) so the owner can judge whether to stock it.
     c) "noise" — typos with negligible hits, bot queries, irrelevant searches. List them
        in one line, no action.
3. Report format:
   - A worklist table: term, hits, classification, suggested fix (concrete: "add word X
     to SKU Y's name", "consider stocking Z — N searches", "-").
   - A 2-3 line summary on top: how much demand (total hits) is recoverable by simple
     naming fixes vs. how much points at missing assortment.

Rules: read-only — do not call any write tool; renaming products or extending the catalog
is the owner's decision, this session produces the worklist. Search demand numbers come
only from the tool — never estimate. Write the report in the language the owner uses in
this conversation.
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
