<?php

declare(strict_types=1);

namespace Yu\McpServer\Model;

use Yu\McpServer\Exception\McpException;

class PromptRegistry
{
    /**
     * @param PromptInterface[] $prompts
     */
    public function __construct(private readonly array $prompts = [])
    {
    }

    /**
     * @return PromptInterface[]
     */
    public function getAll(): array
    {
        return $this->prompts;
    }

    /**
     * Checks whether a prompt with the given name is registered.
     */
    public function has(string $name): bool
    {
        return isset($this->prompts[$name]);
    }

    /**
     * Looks up a registered prompt by name.
     *
     * @throws McpException if no prompt is registered under that name.
     */
    public function get(string $name): PromptInterface
    {
        if (!$this->has($name)) {
            throw new McpException(sprintf('Unknown prompt: %s', $name), -32602);
        }

        return $this->prompts[$name];
    }
}
