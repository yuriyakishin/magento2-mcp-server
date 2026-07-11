<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Prompts\Sales;

use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\PromptInterface;
use Yu\McpServer\Model\Prompts\Sales\SalesTrends;

class SalesTrendsTest extends TestCase
{
    /**
     * The prompt must declare its documented name and the two optional arguments
     * "period" and "compare_to".
     */
    public function testDeclaresPromptContract(): void
    {
        $prompt = new SalesTrends();

        $this->assertInstanceOf(PromptInterface::class, $prompt);
        $this->assertSame('sales_trends', $prompt->getName());

        $arguments = $prompt->getArguments();
        $this->assertCount(2, $arguments);
        $this->assertSame('period', $arguments[0]['name']);
        $this->assertFalse($arguments[0]['required']);
        $this->assertSame('compare_to', $arguments[1]['name']);
        $this->assertFalse($arguments[1]['required']);
    }

    /**
     * Without arguments the prompt renders one user message with the default period
     * and the same-length-before baseline, referencing both comparison tools.
     */
    public function testRendersDefaults(): void
    {
        $messages = (new SalesTrends())->render([]);

        $this->assertCount(1, $messages);
        $this->assertSame('user', $messages[0]['role']);
        $this->assertSame('text', $messages[0]['content']['type']);

        $text = $messages[0]['content']['text'];
        $this->assertStringContainsString('Current period: last 30 days', $text);
        $this->assertStringContainsString('the period of the same length immediately before', $text);
        $this->assertStringContainsString('sales_compare_periods', $text);
        $this->assertStringContainsString('sales_bestsellers', $text);
        // The tool computes the deltas server-side — the model must not redo the math.
        $this->assertStringContainsString('do not recompute', $text);
        // Artifact sandboxes block CDN scripts — charts must be self-contained.
        $this->assertStringContainsString('from a CDN', $text);
    }

    /**
     * Custom periods land verbatim in the rendered instructions.
     */
    public function testRendersCustomPeriods(): void
    {
        $messages = (new SalesTrends())->render([
            'period' => 'this month',
            'compare_to' => 'same period last year',
        ]);

        $text = $messages[0]['content']['text'];
        $this->assertStringContainsString('Current period: this month', $text);
        $this->assertStringContainsString('Baseline: same period last year', $text);
    }

    /**
     * A blank current period must be rejected.
     */
    public function testThrowsOnBlankPeriod(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new SalesTrends())->render(['period' => '']);
    }

    /**
     * A blank baseline must be rejected.
     */
    public function testThrowsOnBlankBaseline(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new SalesTrends())->render(['compare_to' => '  ']);
    }
}
