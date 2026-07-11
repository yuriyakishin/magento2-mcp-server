<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Prompts\Marketing;

use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\PromptInterface;
use Yu\McpServer\Model\Prompts\Marketing\RecoverCarts;

class RecoverCartsTest extends TestCase
{
    /**
     * The prompt must declare its documented name and a single optional "days" argument.
     */
    public function testDeclaresPromptContract(): void
    {
        $prompt = new RecoverCarts();

        $this->assertInstanceOf(PromptInterface::class, $prompt);
        $this->assertSame('recover_carts', $prompt->getName());

        $arguments = $prompt->getArguments();
        $this->assertCount(1, $arguments);
        $this->assertSame('days', $arguments[0]['name']);
        $this->assertFalse($arguments[0]['required']);
    }

    /**
     * Without arguments the prompt renders one user message covering the default 7-day
     * window and referencing the abandoned-carts tool.
     */
    public function testRendersDefaultDays(): void
    {
        $messages = (new RecoverCarts())->render([]);

        $this->assertCount(1, $messages);
        $this->assertSame('user', $messages[0]['role']);
        $this->assertSame('text', $messages[0]['content']['type']);

        $text = $messages[0]['content']['text'];
        $this->assertStringContainsString('last 7 day(s)', $text);
        $this->assertStringContainsString('cart_list_abandoned', $text);
        // The workflow must stay draft-only — nothing is sent automatically.
        $this->assertStringContainsString('nothing is sent by this workflow', $text);
        // Discounts are the owner's call, never invented by the model.
        $this->assertStringContainsString('does not invent discounts', $text);
    }

    /**
     * A custom day count lands verbatim in the rendered instructions.
     */
    public function testRendersCustomDays(): void
    {
        $messages = (new RecoverCarts())->render(['days' => '14']);

        $this->assertStringContainsString('last 14 day(s)', $messages[0]['content']['text']);
    }

    /**
     * A non-numeric day count must be rejected.
     */
    public function testThrowsOnNonNumericDays(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new RecoverCarts())->render(['days' => 'week']);
    }

    /**
     * An out-of-range day count must be rejected.
     */
    public function testThrowsOnOutOfRangeDays(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new RecoverCarts())->render(['days' => '31']);
    }
}
