<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Prompts\Product;

use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\PromptInterface;
use Yu\McpServer\Model\Prompts\Product\RestockAdvisor;

class RestockAdvisorTest extends TestCase
{
    /**
     * The prompt must declare its documented name and a single optional "horizon_days"
     * argument.
     */
    public function testDeclaresPromptContract(): void
    {
        $prompt = new RestockAdvisor();

        $this->assertInstanceOf(PromptInterface::class, $prompt);
        $this->assertSame('restock_advisor', $prompt->getName());

        $arguments = $prompt->getArguments();
        $this->assertCount(1, $arguments);
        $this->assertSame('horizon_days', $arguments[0]['name']);
        $this->assertFalse($arguments[0]['required']);
    }

    /**
     * Without arguments the prompt renders one user message with the default 30-day
     * horizon, referencing both stock tools.
     */
    public function testRendersDefaultHorizon(): void
    {
        $messages = (new RestockAdvisor())->render([]);

        $this->assertCount(1, $messages);
        $this->assertSame('user', $messages[0]['role']);
        $this->assertSame('text', $messages[0]['content']['type']);

        $text = $messages[0]['content']['text'];
        $this->assertStringContainsString('cover 30 day(s)', $text);
        $this->assertStringContainsString('product_low_stock', $text);
        $this->assertStringContainsString('product_sales_velocity', $text);
        // The plan is for the supplier — Magento stock must not be touched.
        $this->assertStringContainsString('do NOT call product_update_stock', $text);
    }

    /**
     * A custom horizon lands verbatim in the rendered instructions.
     */
    public function testRendersCustomHorizon(): void
    {
        $messages = (new RestockAdvisor())->render(['horizon_days' => '60']);

        $this->assertStringContainsString('cover 60 day(s)', $messages[0]['content']['text']);
    }

    /**
     * A non-numeric horizon must be rejected.
     */
    public function testThrowsOnNonNumericHorizon(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new RestockAdvisor())->render(['horizon_days' => 'month']);
    }

    /**
     * An out-of-range horizon must be rejected.
     */
    public function testThrowsOnOutOfRangeHorizon(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new RestockAdvisor())->render(['horizon_days' => '5']);
    }
}
