<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Prompts\Search;

use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\PromptInterface;
use Yu\McpServer\Model\Prompts\Search\ZeroResultRescue;

class ZeroResultRescueTest extends TestCase
{
    /**
     * The prompt must declare its documented name and no arguments.
     */
    public function testDeclaresPromptContract(): void
    {
        $prompt = new ZeroResultRescue();

        $this->assertInstanceOf(PromptInterface::class, $prompt);
        $this->assertSame('zero_result_rescue', $prompt->getName());
        $this->assertSame([], $prompt->getArguments());
    }

    /**
     * The prompt renders one user message referencing the search report and catalog
     * probing, and stays read-only.
     */
    public function testRendersInstructions(): void
    {
        $messages = (new ZeroResultRescue())->render([]);

        $this->assertCount(1, $messages);
        $this->assertSame('user', $messages[0]['role']);
        $this->assertSame('text', $messages[0]['content']['type']);

        $text = $messages[0]['content']['text'];
        $this->assertStringContainsString('search_terms_report', $text);
        $this->assertStringContainsString('product_search', $text);
        // The session produces a worklist — it never writes anything itself.
        $this->assertStringContainsString('do not call any write tool', $text);
        // The three classification buckets must be spelled out.
        $this->assertStringContainsString('naming gap', $text);
        $this->assertStringContainsString('assortment gap', $text);
        $this->assertStringContainsString('noise', $text);
    }
}
