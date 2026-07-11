<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Prompts\Product;

use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\PromptInterface;
use Yu\McpServer\Model\Prompts\Product\ContentWriter;

class ContentWriterTest extends TestCase
{
    /**
     * The prompt must declare its documented name and a single optional "limit" argument.
     */
    public function testDeclaresPromptContract(): void
    {
        $prompt = new ContentWriter();

        $this->assertInstanceOf(PromptInterface::class, $prompt);
        $this->assertSame('product_content_writer', $prompt->getName());

        $arguments = $prompt->getArguments();
        $this->assertCount(1, $arguments);
        $this->assertSame('limit', $arguments[0]['name']);
        $this->assertFalse($arguments[0]['required']);
    }

    /**
     * Without arguments the prompt renders one user message for the default 5 products,
     * referencing the gap-finding and update tools with the per-product confirmation gate.
     */
    public function testRendersDefaultLimit(): void
    {
        $messages = (new ContentWriter())->render([]);

        $this->assertCount(1, $messages);
        $this->assertSame('user', $messages[0]['role']);
        $this->assertSame('text', $messages[0]['content']['type']);

        $text = $messages[0]['content']['text'];
        $this->assertStringContainsString('up to 5 product(s)', $text);
        $this->assertStringContainsString('catalog_health_report', $text);
        $this->assertStringContainsString('product_get', $text);
        $this->assertStringContainsString('product_update', $text);
        // Every text goes live only after the owner approved that exact product.
        $this->assertStringContainsString('WAIT for the owner\'s verdict on THAT product', $text);
        // product_update cannot write meta fields — the prompt must say so.
        $this->assertStringContainsString('meta descriptions are NOT editable via product_update', $text);
        // Content sessions never touch commercial fields.
        $this->assertStringContainsString('Do not touch price, status', $text);
    }

    /**
     * A custom limit lands verbatim in the rendered instructions.
     */
    public function testRendersCustomLimit(): void
    {
        $messages = (new ContentWriter())->render(['limit' => '3']);

        $this->assertStringContainsString('up to 3 product(s)', $messages[0]['content']['text']);
    }

    /**
     * A non-numeric limit must be rejected.
     */
    public function testThrowsOnNonNumericLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ContentWriter())->render(['limit' => 'all']);
    }

    /**
     * An out-of-range limit must be rejected.
     */
    public function testThrowsOnOutOfRangeLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ContentWriter())->render(['limit' => '11']);
    }
}
