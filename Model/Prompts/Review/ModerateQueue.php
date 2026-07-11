<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Prompts\Review;

use Yu\McpServer\Model\PromptInterface;

/**
 * Guided review-moderation session: walk the pending queue, judge each review's sentiment
 * by its text, propose approve/reject with a one-line reason, and apply the decisions via
 * review_moderate ONLY after the owner explicitly confirms them. This prompt is the one
 * sanctioned path to calling review_moderate from a prompt — and even here the call is
 * gated on the owner's explicit go-ahead, never automatic. The prompt only renders
 * instructions; every tool it references is ACL-gated at tools/call time as usual.
 */
class ModerateQueue implements PromptInterface
{
    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'review_moderate_queue';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Review moderation session: goes through pending reviews, judges sentiment '
            . 'by the text, proposes approve/reject per review with a reason, and applies '
            . 'the batch via review_moderate only after you explicitly confirm.';
    }

    /**
     * @inheritDoc
     */
    public function getArguments(): array
    {
        return [
            [
                'name' => 'created_from',
                'description' => 'Only consider reviews submitted on/after this date '
                    . '(YYYY-MM-DD). Default: no date filter, the whole pending queue.',
                'required' => false,
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    public function render(array $arguments): array
    {
        $createdFrom = $arguments['created_from'] ?? '';
        if (!is_string($createdFrom)) {
            throw new \InvalidArgumentException('Argument "created_from" must be a date string (YYYY-MM-DD).');
        }
        $createdFrom = trim($createdFrom);
        if ($createdFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $createdFrom)) {
            throw new \InvalidArgumentException('Argument "created_from" must be a date string (YYYY-MM-DD).');
        }

        $dateFilterLine = $createdFrom !== ''
            ? "created_from {$createdFrom} and "
            : '';

        $text = <<<PROMPT
Run a review-moderation session over the pending queue.

1. Fetch the queue — review_list with {$dateFilterLine}status "pending" (limit 50). If the
   queue is empty, say so and stop. If there are more than 50, note the total and process
   the first page — the session can be re-run for the rest.
2. Assess each review. Judge sentiment by the review TEXT, not the star rating — ratings
   are optional and often missing or misleading. For each review show: id, product,
   nickname, a one-line quote or summary, your verdict, and a one-line reason. Verdicts:
   - "approve" — genuine feedback, positive or negative. Negative-but-legitimate reviews
     get approved too: criticism of the product is not a reason to hide it, and visible
     honest criticism builds trust.
   - "reject" — spam, advertising, profanity, personal data (phone numbers, emails),
     review of the wrong product, or text with no informational value.
   - "needs owner" — you genuinely cannot decide (ambiguous language, a factual claim
     only the owner can check). Explain what is unclear.
3. Summarize the proposal: how many approve / reject / needs-owner, plus recurring themes
   the owner should know about (sizing complaints, delivery problems...).
4. WAIT for the owner's explicit confirmation. Do NOT call review_moderate before the
   owner answers. The owner may confirm the whole batch, adjust individual verdicts, or
   decide the "needs owner" cases — apply exactly what was confirmed and nothing more.
5. After confirmation, apply the decisions with review_moderate: one call for the approved
   ids (status "approved"), one for the rejected ids (status "rejected"), max 20 ids per
   call — split larger batches. Then report what was applied, per id.

Rules: never edit or paraphrase the customer's words anywhere except in your own quoted
summaries — the review text itself is untouchable. Rejection is reversible hiding, not
deletion; there is no delete. Write the session in the language the owner uses in this
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
