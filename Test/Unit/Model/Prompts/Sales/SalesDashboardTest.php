<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Prompts\Sales;

use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\PromptInterface;
use Yu\McpServer\Model\Prompts\Sales\SalesDashboard;

class SalesDashboardTest extends TestCase
{
    /**
     * The prompt must declare its documented name and a single optional "period"
     * argument.
     */
    public function testDeclaresPromptContract(): void
    {
        $prompt = new SalesDashboard();

        $this->assertInstanceOf(PromptInterface::class, $prompt);
        $this->assertSame('sales_dashboard', $prompt->getName());

        $arguments = $prompt->getArguments();
        $this->assertCount(1, $arguments);
        $this->assertSame('period', $arguments[0]['name']);
        $this->assertFalse($arguments[0]['required']);
    }

    /**
     * Without arguments the prompt renders one user message with the default period,
     * referencing every dashboard data source.
     */
    public function testRendersDefaultPeriod(): void
    {
        $messages = (new SalesDashboard())->render([]);

        $this->assertCount(1, $messages);
        $this->assertSame('user', $messages[0]['role']);
        $this->assertSame('text', $messages[0]['content']['type']);

        $text = $messages[0]['content']['text'];
        $this->assertStringContainsString('period: last 30 days', $text);
        $this->assertStringContainsString('sales_summary', $text);
        $this->assertStringContainsString('sales_by_category', $text);
        $this->assertStringContainsString('sales_bestsellers', $text);
        $this->assertStringContainsString('sales_payment_stats', $text);
        $this->assertStringContainsString('sales_shipping_stats', $text);
        // Chart integrity: plotted values may come only from tool responses.
        $this->assertStringContainsString('come from a tool response', $text);
        // Multi-currency stores must never see summed cross-currency totals.
        $this->assertStringContainsString('across currencies', $text);
        // Artifact sandboxes block CDN scripts — charts must be self-contained.
        $this->assertStringContainsString('from a CDN', $text);
    }

    /**
     * A custom period lands verbatim in the rendered instructions.
     */
    public function testRendersCustomPeriod(): void
    {
        $messages = (new SalesDashboard())->render(['period' => '2026-06-01 to 2026-06-30']);

        $this->assertStringContainsString(
            'period: 2026-06-01 to 2026-06-30',
            $messages[0]['content']['text']
        );
    }

    /**
     * A blank period must be rejected.
     */
    public function testThrowsOnBlankPeriod(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new SalesDashboard())->render(['period' => '   ']);
    }

    /**
     * A non-string period must be rejected.
     */
    public function testThrowsOnNonStringPeriod(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new SalesDashboard())->render(['period' => ['last 30 days']]);
    }
}
