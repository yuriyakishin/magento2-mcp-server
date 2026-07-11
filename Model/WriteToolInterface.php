<?php

declare(strict_types=1);

namespace Yu\McpServer\Model;

/**
 * Marker interface for tools that modify store data (create operations).
 *
 * Write tools are additionally gated by the mcp/general/enable_write_tools system config
 * flag (default off): when the flag is disabled, JsonRpcHandler hides them from tools/list
 * and rejects tools/call on them, regardless of the caller's ACL rights. ACL answers "who
 * may write", the flag answers "is writing enabled on this installation at all".
 *
 * Every write tool must also declare a non-null getRequiredAclResource() — anonymous
 * writes must be impossible by construction.
 */
interface WriteToolInterface extends ToolInterface
{
}
