<?php

declare(strict_types=1);

namespace Yu\McpServer\Model;

interface ToolInterface
{
    /**
     * Unique, snake_case tool name as exposed to MCP clients.
     */
    public function getName(): string;

    /**
     * Human-readable description shown to the LLM client in tools/list.
     */
    public function getDescription(): string;

    /**
     * @return array JSON Schema (type/properties/required) describing the tool's arguments.
     */
    public function getInputSchema(): array;

    /**
     * Magento ACL resource required to call this tool, or null if it's public.
     */
    public function getRequiredAclResource(): ?string;

    /**
     * Errors meant for the caller are thrown as exactly \InvalidArgumentException (bad
     * arguments) or \RuntimeException (e.g. "not found") — only those two classes have
     * their message shown to the client. Any other exception, subclasses included, is
     * reported as an internal error with a log reference.
     *
     * @param array $arguments Tool call arguments, already matching getInputSchema().
     * @return array Associative result data to be JSON-encoded into the tool response.
     */
    public function execute(array $arguments): array;
}
