<?php

declare(strict_types=1);

namespace Yu\McpServer\Model;

use Yu\McpServer\Model\Auth\AdminContext;

/**
 * A tool whose answer legitimately differs between an anonymous caller and an
 * authenticated admin — the "two storefronts" pattern: anonymous callers get exactly what
 * a customer sees on the storefront (e.g. enabled products only), while an admin holding
 * the relevant ACL resource also sees back-office state (disabled products, drafts) with
 * that state clearly marked in the response.
 *
 * This is for tools that stay public (getRequiredAclResource() === null) but can say MORE
 * to a recognized admin. A tool whose data is entirely restricted doesn't belong here —
 * it declares an ACL resource instead.
 */
interface ContextAwareToolInterface extends ToolInterface
{
    /**
     * Executes the tool with the caller's resolved identity (null = anonymous).
     * ToolInterface::execute() must behave exactly like executeWithContext($args, null).
     *
     * @param array $arguments Tool call arguments, already matching getInputSchema().
     * @param AdminContext|null $adminContext The caller's identity, or null for anonymous.
     * @return array Associative result data to be JSON-encoded into the tool response.
     */
    public function executeWithContext(array $arguments, ?AdminContext $adminContext): array;
}
