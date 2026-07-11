<?php

declare(strict_types=1);

namespace Yu\McpServer\Model;

/**
 * Contract for MCP prompts: reusable, parameterized task templates the user invokes from
 * the client's UI (slash-command style). A prompt renders instruction text only — it never
 * executes business logic itself; the tools it references are called by the model later
 * and stay individually ACL-gated, which is why prompts need no ACL resource of their own.
 */
interface PromptInterface
{
    /**
     * Unique, snake_case prompt name as exposed to MCP clients.
     */
    public function getName(): string;

    /**
     * Human-readable description shown in the client's prompt picker.
     */
    public function getDescription(): string;

    /**
     * Argument declarations per the MCP prompts spec.
     *
     * @return array<int, array{name: string, description: string, required: bool}>
     */
    public function getArguments(): array;

    /**
     * Renders the prompt into MCP messages.
     *
     * @param array<string, string> $arguments Values for the declared arguments.
     * @return array<int, array{role: string, content: array{type: string, text: string}}>
     * @throws \InvalidArgumentException on malformed argument values.
     */
    public function render(array $arguments): array;
}
