<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Prompts\Product;

use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\PromptInterface;
use Yu\McpServer\Model\Prompts\Product\ProductPerformance;

class ProductPerformanceTest extends TestCase
{
    /**
     * The prompt must declare its documented name and the two optional arguments
     * "period" and "top".
     */
    public function testDeclaresPromptContract(): void
    {
        $prompt = new ProductPerformance();

        $this->assertInstanceOf(PromptInterface::class, $prompt);
        $this->assertSame('product_performance', $prompt->getName());

        $arguments = $prompt->getArguments();
        $this->assertCount(2, $arguments);
        $this->assertSame('period', $arguments[0]['name']);
        $this->assertFalse($arguments[0]['required']);
        $this->assertSame('top', $arguments[1]['name']);
        $this->assertFalse($arguments[1]['required']);
    }

    /**
     * Without arguments the prompt renders one user message with the defaults,
     * referencing both rankings and the velocity tool.
     */
    public function testRendersDefaults(): void
    {
        $messages = (new ProductPerformance())->render([]);

        $this->assertCount(1, $messages);
        $this->assertSame('user', $messages[0]['role']);
        $this->assertSame('text', $messages[0]['content']['type']);

        $text = $messages[0]['content']['text'];
        $this->assertStringContainsString('period: last 30 days', $text);
        $this->assertStringContainsString('top 10', $text);
        $this->assertStringContainsString('sales_bestsellers', $text);
        $this->assertStringContainsString('product_sales_velocity', $text);
        // The report is analytics only — Magento stock must not be touched.
        $this->assertStringContainsString('do NOT call product_update_stock', $text);
        // Artifact sandboxes block CDN scripts — charts must be self-contained.
        $this->assertStringContainsString('from a CDN', $text);
    }

    /**
     * Custom arguments land verbatim in the rendered instructions.
     */
    public function testRendersCustomArguments(): void
    {
        $messages = (new ProductPerformance())->render([
            'period' => 'last 7 days',
            'top' => 5,
        ]);

        $text = $messages[0]['content']['text'];
        $this->assertStringContainsString('period: last 7 days', $text);
        $this->assertStringContainsString('top 5', $text);
        $this->assertStringContainsString('limit 5', $text);
    }

    /**
     * A blank period must be rejected.
     */
    public function testThrowsOnBlankPeriod(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ProductPerformance())->render(['period' => ' ']);
    }

    /**
     * A non-numeric "top" must be rejected.
     */
    public function testThrowsOnNonNumericTop(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ProductPerformance())->render(['top' => 'many']);
    }

    /**
     * An out-of-range "top" must be rejected.
     */
    public function testThrowsOnOutOfRangeTop(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ProductPerformance())->render(['top' => '16']);
    }
}
