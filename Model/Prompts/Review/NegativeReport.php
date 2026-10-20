<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Prompts\Review;

use Yu\McpServer\Model\PromptInterface;

/**
 * Negative-review content scan: fetches reviews of ANY moderation status over an explicit
 * date window and surfaces only the ones that read negative by their TEXT — never by the
 * star rating, since a rating is optional and often missing (review_list's rating_min/
 * rating_max filters silently drop unrated reviews, which is exactly the gap this prompt
 * exists to cover). For the pending subset it proposes a moderation verdict using the same
 * rules as review_moderate_queue and, like that prompt, applies review_moderate ONLY after
 * the owner explicitly confirms. The prompt only renders instructions; every tool it
 * references is ACL-gated at tools/call time as usual.
 */
class NegativeReport implements PromptInterface
{
    private const DEFAULT_WINDOW_DESCRIPTION = '7 days before today (store local time)';

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'review_negative_report';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Negative-review scan over an explicit date range, across all moderation '
            . 'statuses: judges sentiment by the review TEXT (not the star rating, which is '
            . 'optional and often missing), reports only the negative ones grouped by theme, '
            . 'and proposes an approve/reject verdict for the pending subset — applied via '
            . 'review_moderate only after you explicitly confirm.';
    }

    /**
     * @inheritDoc
     */
    public function getArguments(): array
    {
        return [
            [
                'name' => 'created_from',
                'description' => 'Start of the review window (YYYY-MM-DD), inclusive. '
                    . 'Default: 7 days before today.',
                'required' => false,
            ],
            [
                'name' => 'created_to',
                'description' => 'End of the review window (YYYY-MM-DD), inclusive. '
                    . 'Default: today.',
                'required' => false,
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    public function render(array $arguments): array
    {
        $createdFrom = $this->dateArgument($arguments, 'created_from');
        $createdTo = $this->dateArgument($arguments, 'created_to');
        if ($createdFrom !== '' && $createdTo !== '' && $createdFrom > $createdTo) {
            throw new \InvalidArgumentException('Argument "created_from" must not be after "created_to".');
        }

        $fromDescription = $createdFrom !== '' ? $createdFrom : self::DEFAULT_WINDOW_DESCRIPTION;
        $toDescription = $createdTo !== '' ? $createdTo : 'today (store local time)';

        $text = <<<PROMPT
Run a negative-review content scan.

1. Resolve the window into concrete store-local dates: created_from {$fromDescription}, created_to
   {$toDescription}.
2. Fetch the reviews — review_list with the resolved created_from/created_to, across all
   moderation statuses (no status filter) and with no rating filter — ratings are optional
   and often missing or misleading, so filtering on them would silently drop unrated
   reviews. Use limit 50; if there are more than 50, note the total and process the first
   page — the scan can be re-run for the rest.
3. Judge each review's sentiment by its TEXT, not the star rating. Keep only the negative
   ones and report each with: id, status, product, a one-line quote, and why it reads
   negative. Drop positive/neutral reviews from the report entirely.
4. Summarize recurring themes across the negative set (sizing, delivery, quality, product
   description mismatches...) so the owner sees the pattern, not just a list.
5. For the negative reviews whose status is "pending", propose a verdict using these rules:
   - "approve" — genuine feedback, even harsh. Negative-but-legitimate criticism of the
     product is not a reason to hide it; visible honest criticism builds trust.
   - "reject" — spam, advertising, profanity, personal data (phone numbers, emails), review
     of the wrong product, or text with no informational value.
   - "needs owner" — you genuinely cannot decide. Explain what is unclear.
   Negative reviews already "approved" or "not_approved" are reported only, with no verdict —
   that moderation decision is already final and this scan does not reopen it.
6. If there are any pending verdicts, WAIT for the owner's explicit confirmation first.
   Do NOT call review_moderate before the owner answers. The owner may confirm the whole
   batch, adjust individual verdicts, or decide the "needs owner" cases — apply exactly what
   was confirmed and nothing more.
7. After confirmation, apply the decisions with review_moderate: one call for the approved
   ids (status "approved"), one for the rejected ids (status "rejected"), max 20 ids per
   call — split larger batches. Then report what was applied, per id.

Rules: never edit or paraphrase the customer's words anywhere except in your own quoted
summaries — the review text itself is untouchable. Rejection is reversible hiding, not
deletion; there is no delete. Negative content alone is never a reason to reject a review.
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

    /**
     * Validates an optional "YYYY-MM-DD" date argument.
     *
     * @param array $arguments Raw MCP prompt arguments.
     * @param string $key Argument name to read and validate.
     * @return string The trimmed date string, or '' when the argument was not given.
     */
    private function dateArgument(array $arguments, string $key): string
    {
        $value = $arguments[$key] ?? '';
        if (!is_string($value)) {
            throw new \InvalidArgumentException(sprintf('Argument "%s" must be a date string (YYYY-MM-DD).', $key));
        }
        $value = trim($value);
        if ($value !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new \InvalidArgumentException(sprintf('Argument "%s" must be a date string (YYYY-MM-DD).', $key));
        }

        return $value;
    }
}