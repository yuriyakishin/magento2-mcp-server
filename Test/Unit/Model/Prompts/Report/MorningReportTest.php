<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Prompts\Report;

use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\PromptInterface;
use Yu\McpServer\Model\Prompts\Report\MorningReport;

class MorningReportTest extends TestCase
{
    /**
     * The prompt must declare its documented name and a single optional "period" argument.
     */
    public function testDeclaresPromptContract(): void
    {
        $prompt = new MorningReport();

        $this->assertInstanceOf(PromptInterface::class, $prompt);
        $this->assertSame('morning_report', $prompt->getName());

        $arguments = $prompt->getArguments();
        $this->assertCount(1, $arguments);
        $this->assertSame('period', $arguments[0]['name']);
        $this->assertFalse($arguments[0]['required']);
    }

    /**
     * Without arguments the prompt renders one user message covering all five report
     * sections for the default "yesterday" period.
     */
    public function testRendersDefaultPeriod(): void
    {
        $messages = (new MorningReport())->render([]);

        $this->assertCount(1, $messages);
        $this->assertSame('user', $messages[0]['role']);
        $this->assertSame('text', $messages[0]['content']['type']);

        $text = $messages[0]['content']['text'];
        $this->assertStringContainsString('period: yesterday', $text);
        foreach (
            [
                'sales_summary',
                'order_list',
                'review_list',
                'cart_list_abandoned',
                'product_low_stock',
            ] as $tool
        ) {
            $this->assertStringContainsString($tool, $text);
        }
        // Moderation must stay a human decision — the prompt forbids unconfirmed writes.
        $this->assertStringContainsString('NOT call review_moderate', $text);
    }

    /**
     * A custom period lands verbatim in the rendered instructions.
     */
    public function testRendersCustomPeriod(): void
    {
        $messages = (new MorningReport())->render(['period' => '2026-07-01 to 2026-07-07']);

        $this->assertStringContainsString(
            'period: 2026-07-01 to 2026-07-07',
            $messages[0]['content']['text']
        );
    }

    /**
     * A malformed period value must be rejected.
     */
    public function testThrowsOnInvalidPeriod(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new MorningReport())->render(['period' => '   ']);
    }
}
