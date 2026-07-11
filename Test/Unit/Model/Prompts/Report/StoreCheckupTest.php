<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Prompts\Report;

use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\PromptInterface;
use Yu\McpServer\Model\Prompts\Report\StoreCheckup;

class StoreCheckupTest extends TestCase
{
    /**
     * The prompt must declare its documented name and a single optional "period" argument.
     */
    public function testDeclaresPromptContract(): void
    {
        $prompt = new StoreCheckup();

        $this->assertInstanceOf(PromptInterface::class, $prompt);
        $this->assertSame('store_checkup', $prompt->getName());

        $arguments = $prompt->getArguments();
        $this->assertCount(1, $arguments);
        $this->assertSame('period', $arguments[0]['name']);
        $this->assertFalse($arguments[0]['required']);
    }

    /**
     * Without arguments the prompt renders one user message covering all five checkup
     * sections for the default "last 30 days" period.
     */
    public function testRendersDefaultPeriod(): void
    {
        $messages = (new StoreCheckup())->render([]);

        $this->assertCount(1, $messages);
        $this->assertSame('user', $messages[0]['role']);
        $this->assertSame('text', $messages[0]['content']['type']);

        $text = $messages[0]['content']['text'];
        $this->assertStringContainsString('last 30 days', $text);
        foreach (
            [
                'system_health',
                'catalog_health_report',
                'search_terms_report',
                'review_list',
                'product_low_stock',
                'product_sales_velocity',
            ] as $tool
        ) {
            $this->assertStringContainsString($tool, $text);
        }
        // The checkup diagnoses only — writes stay a human decision.
        $this->assertStringContainsString('NOT call review_moderate', $text);
        $this->assertStringContainsString('do not call any write tool', $text);
    }

    /**
     * A custom period lands verbatim in the rendered instructions.
     */
    public function testRendersCustomPeriod(): void
    {
        $messages = (new StoreCheckup())->render(['period' => '2026-06-01 to 2026-06-30']);

        $this->assertStringContainsString(
            '2026-06-01 to 2026-06-30',
            $messages[0]['content']['text']
        );
    }

    /**
     * A malformed period value must be rejected.
     */
    public function testThrowsOnInvalidPeriod(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new StoreCheckup())->render(['period' => '   ']);
    }
}
