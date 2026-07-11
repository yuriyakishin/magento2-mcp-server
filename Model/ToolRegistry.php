<?php

declare(strict_types=1);

namespace Yu\McpServer\Model;

use Yu\McpServer\Exception\McpException;

class ToolRegistry
{
    /**
     * @param ToolInterface[] $tools
     */
    public function __construct(private readonly array $tools = [])
    {
    }

    /**
     * @return ToolInterface[]
     */
    public function getAll(): array
    {
        return $this->tools;
    }

    /**
     * Checks whether a tool with the given name is registered.
     */
    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /**
     * Looks up a registered tool by name.
     *
     * @throws McpException if no tool is registered under that name.
     */
    public function get(string $name): ToolInterface
    {
        if (!$this->has($name)) {
            throw new McpException(sprintf('Unknown tool: %s', $name), -32602);
        }

        return $this->tools[$name];
    }
}
