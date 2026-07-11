<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Prompts\Review;

use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\PromptInterface;
use Yu\McpServer\Model\Prompts\Review\ModerateQueue;

class ModerateQueueTest extends TestCase
{
    /**
     * The prompt must declare its documented name and a single optional "created_from"
     * argument.
     */
    public function testDeclaresPromptContract(): void
    {
        $prompt = new ModerateQueue();

        $this->assertInstanceOf(PromptInterface::class, $prompt);
        $this->assertSame('review_moderate_queue', $prompt->getName());

        $arguments = $prompt->getArguments();
        $this->assertCount(1, $arguments);
        $this->assertSame('created_from', $arguments[0]['name']);
        $this->assertFalse($arguments[0]['required']);
    }

    /**
     * Without arguments the prompt renders one user message covering the whole pending
     * queue with the confirmation gate in place.
     */
    public function testRendersWithoutDateFilter(): void
    {
        $messages = (new ModerateQueue())->render([]);

        $this->assertCount(1, $messages);
        $this->assertSame('user', $messages[0]['role']);
        $this->assertSame('text', $messages[0]['content']['type']);

        $text = $messages[0]['content']['text'];
        $this->assertStringContainsString('review_list', $text);
        $this->assertStringContainsString('status "pending"', $text);
        $this->assertStringNotContainsString('created_from  and', $text);
        // Sentiment comes from the text, not the stars.
        $this->assertStringContainsString('TEXT, not the star rating', $text);
        // Moderation is applied only after the owner's explicit confirmation.
        $this->assertStringContainsString('Do NOT call review_moderate before the', $text);
        // The batch tool caps at 20 ids per call — the instructions must respect that.
        $this->assertStringContainsString('max 20 ids per', $text);
    }

    /**
     * A date filter lands in the review_list instruction.
     */
    public function testRendersWithDateFilter(): void
    {
        $messages = (new ModerateQueue())->render(['created_from' => '2026-07-01']);

        $this->assertStringContainsString(
            'created_from 2026-07-01',
            $messages[0]['content']['text']
        );
    }

    /**
     * A malformed date must be rejected.
     */
    public function testThrowsOnMalformedDate(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ModerateQueue())->render(['created_from' => 'last week']);
    }
}
