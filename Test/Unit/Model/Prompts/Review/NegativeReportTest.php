<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Prompts\Review;

use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\PromptInterface;
use Yu\McpServer\Model\Prompts\Review\NegativeReport;

class NegativeReportTest extends TestCase
{
    /**
     * The prompt must declare its documented name and two optional date-range arguments.
     */
    public function testDeclaresPromptContract(): void
    {
        $prompt = new NegativeReport();

        $this->assertInstanceOf(PromptInterface::class, $prompt);
        $this->assertSame('review_negative_report', $prompt->getName());

        $arguments = $prompt->getArguments();
        $this->assertCount(2, $arguments);
        $this->assertSame('created_from', $arguments[0]['name']);
        $this->assertFalse($arguments[0]['required']);
        $this->assertSame('created_to', $arguments[1]['name']);
        $this->assertFalse($arguments[1]['required']);
    }

    /**
     * Without arguments the prompt falls back to a 7-days-before-today default window and
     * scans all moderation statuses without a rating filter.
     */
    public function testRendersWithDefaultWindow(): void
    {
        $messages = (new NegativeReport())->render([]);

        $this->assertCount(1, $messages);
        $this->assertSame('user', $messages[0]['role']);
        $this->assertSame('text', $messages[0]['content']['type']);

        $text = $messages[0]['content']['text'];
        $this->assertStringContainsString('review_list', $text);
        $this->assertStringContainsString('7 days before today', $text);
        $this->assertStringContainsString('no status filter', $text);
        $this->assertStringContainsString('no rating filter', $text);
        // Sentiment comes from the text, not the stars.
        $this->assertStringContainsString('TEXT, not the star rating', $text);
        // Moderation is applied only after the owner's explicit confirmation.
        $this->assertStringContainsString('Do NOT call review_moderate before the', $text);
        // Already-decided reviews are reported only, never re-verdicted.
        $this->assertStringContainsString('this scan does not reopen it', $text);
        // The batch tool caps at 20 ids per call.
        $this->assertStringContainsString('max 20 ids per', $text);
    }

    /**
     * Explicit dates land in the resolved window instruction verbatim.
     */
    public function testRendersWithExplicitWindow(): void
    {
        $messages = (new NegativeReport())->render([
            'created_from' => '2026-07-01',
            'created_to' => '2026-07-10',
        ]);

        $text = $messages[0]['content']['text'];
        $this->assertStringContainsString('created_from 2026-07-01', $text);
        $this->assertStringContainsString('created_to', $text);
        $this->assertStringContainsString('2026-07-10', $text);
    }

    /**
     * A malformed date must be rejected.
     */
    public function testThrowsOnMalformedDate(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new NegativeReport())->render(['created_from' => 'last week']);
    }

    /**
     * created_from after created_to is rejected.
     */
    public function testThrowsWhenFromIsAfterTo(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new NegativeReport())->render([
            'created_from' => '2026-07-10',
            'created_to' => '2026-07-01',
        ]);
    }
}