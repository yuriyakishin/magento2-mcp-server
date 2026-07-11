<?php

declare(strict_types=1);

namespace Yu\McpServer\Model;

use Psr\Log\LoggerInterface;
use Yu\McpServer\Exception\ForbiddenException;
use Yu\McpServer\Exception\McpException;
use Yu\McpServer\Exception\UnauthorizedException;
use Yu\McpServer\Model\Auth\AdminContext;

class JsonRpcHandler
{
    /**
     * Protocol revisions this server complies with, newest first. The server implements
     * the Streamable HTTP transport and OAuth authorization from 2025-03-26+, uses none
     * of the features removed later (it never initiates SSE or relies on client batching),
     * and treats everything added after 2024-11-05 (tasks, elicitation, structured output)
     * as the optional capabilities they are — so all four revisions are safe to accept.
     */
    private const SUPPORTED_PROTOCOL_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];
    private const INVALID_REQUEST = -32600;
    private const METHOD_NOT_FOUND = -32601;
    private const INVALID_PARAMS = -32602;

    public function __construct(
        private readonly ToolRegistry $toolRegistry,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly PromptRegistry $promptRegistry
    ) {
    }

    /**
     * Dispatches a batch (array) of JSON-RPC messages and collects their responses.
     *
     * @param array[] $messages Decoded JSON-RPC requests/notifications.
     * @return array[] Responses, in the same order, with notifications omitted.
     * @throws UnauthorizedException if any message in the batch calls a restricted tool
     *     anonymously — aborts the whole batch rather than returning partial responses, so
     *     the caller can turn it into a single HTTP 401 (see handle()).
     */
    public function handleBatch(array $messages, ?AdminContext $adminContext = null): array
    {
        $responses = [];
        foreach ($messages as $message) {
            $response = $this->handle($message, $adminContext);
            if ($response !== null) {
                $responses[] = $response;
            }
        }

        return $responses;
    }

    /**
     * Dispatches a single JSON-RPC message and builds its response.
     *
     * @param array $message Decoded JSON-RPC request or notification.
     * @param AdminContext|null $adminContext The caller's resolved identity, or null for
     *     an anonymous request (no bearer token was supplied).
     * @return array|null The JSON-RPC response, or null for notifications (no response body).
     * @throws UnauthorizedException if a `tools/call` targets a restricted tool anonymously —
     *     see the catch block below for why this isn't just returned as a JSON-RPC error.
     */
    public function handle(array $message, ?AdminContext $adminContext = null): ?array
    {
        $isNotification = !array_key_exists('id', $message);
        $id = $message['id'] ?? null;
        $method = $message['method'] ?? null;
        $startedAt = microtime(true);

        try {
            if (!is_string($method) || $method === '') {
                throw new McpException('Invalid request: missing method', self::INVALID_REQUEST);
            }

            $params = $message['params'] ?? [];
            if (!is_array($params)) {
                throw new McpException('Invalid request: params must be an object', self::INVALID_REQUEST);
            }

            $result = match ($method) {
                'initialize' => $this->initialize($params),
                'notifications/initialized' => null,
                'tools/list' => $this->toolsList($adminContext),
                'tools/call' => $this->toolsCall($params, $adminContext),
                'prompts/list' => $this->promptsList(),
                'prompts/get' => $this->promptsGet($params),
                'ping' => new \stdClass(),
                default => throw new McpException(sprintf('Method not found: %s', $method), self::METHOD_NOT_FOUND),
            };

            $this->logger->info(sprintf(
                'jsonrpc method "%s" handled in %.3fs',
                $method,
                microtime(true) - $startedAt
            ));

            if ($isNotification) {
                return null;
            }

            return [
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => $result,
            ];
        } catch (UnauthorizedException $e) {
            // Deliberately not caught into a JSON-RPC error body here: it must reach
            // Controller/Index/Index.php as a thrown exception so it becomes a real HTTP 401
            // with a WWW-Authenticate header, the same way an invalid bearer token does. An
            // OAuth-aware client only starts the authorization dance after an actual 401 — a
            // 200 response with an `error` object inside it (which is what happens to every
            // other McpException) gives it no such signal, so the client would just report
            // "tool call failed" instead of prompting the user to log in.
            $this->logger->warning(sprintf(
                'jsonrpc method "%s" failed in %.3fs: %s',
                $method ?? 'unknown',
                microtime(true) - $startedAt,
                $e->getMessage()
            ));

            throw $e;
        } catch (McpException $e) {
            $this->logger->warning(sprintf(
                'jsonrpc method "%s" failed in %.3fs: %s',
                $method ?? 'unknown',
                microtime(true) - $startedAt,
                $e->getMessage()
            ));

            if ($isNotification) {
                return null;
            }

            return [
                'jsonrpc' => '2.0',
                'id' => $id,
                'error' => [
                    'code' => $e->getRpcCode(),
                    'message' => $e->getMessage(),
                ],
            ];
        }
    }

    /**
     * @param array $params The client's `initialize` params (protocolVersion, clientInfo, ...).
     * @return array The `initialize` handshake result: protocol version, capabilities, server info.
     *
     * Version negotiation per the MCP spec: respond with the client's requested protocol
     * version when this server supports it, otherwise with the latest version it does
     * support — a fixed old version makes modern clients (claude.ai) treat the server as
     * incompatible and silently drop the connection right after a successful OAuth flow.
     */
    private function initialize(array $params): array
    {
        $requestedVersion = $params['protocolVersion'] ?? null;
        $protocolVersion = in_array($requestedVersion, self::SUPPORTED_PROTOCOL_VERSIONS, true)
            ? $requestedVersion
            : self::SUPPORTED_PROTOCOL_VERSIONS[0];

        return [
            'protocolVersion' => $protocolVersion,
            'capabilities' => [
                'tools' => new \stdClass(),
                'prompts' => new \stdClass(),
            ],
            'serverInfo' => [
                'name' => 'yu-mcp-server',
                'version' => '1.0.0',
            ],
        ];
    }

    /**
     * @return array The `tools/list` result: every registered tool, regardless of ACL.
     *
     * Deliberately not filtered by AdminContext. Restricted tools must stay visible to
     * anonymous callers so an OAuth-aware client (claude.ai, Claude Desktop) can discover
     * them and attempt to call them — that attempt is what produces the HTTP 401 that
     * triggers the client's login flow (see toolsCall()/handle()). Filtering them out here
     * would make restricted tools invisible and permanently unreachable through a normal
     * chat session: the model never learns they exist, so it never calls them, so it never
     * hits the 401 that would prompt the user to log in. Access control still happens —
     * just at `tools/call` time, not at `tools/list` time.
     */
    private function toolsList(?AdminContext $adminContext): array
    {
        $tools = [];
        foreach ($this->toolRegistry->getAll() as $tool) {
            // Write tools behind the disabled feature flag are hidden entirely — unlike
            // ACL-restricted read tools, there is no login flow that could unlock them,
            // so listing them would only invite calls that can never succeed.
            if ($this->isDisabledWriteTool($tool)) {
                continue;
            }
            $tools[] = [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'inputSchema' => $tool->getInputSchema(),
            ];
        }

        return ['tools' => $tools];
    }

    /**
     * Executes a `tools/call` request, converting tool exceptions into `isError` results
     * instead of JSON-RPC errors. ACL failures, by contrast, are protocol-level errors
     * (Unauthorized/Forbidden), not tool business-logic errors.
     *
     * @param array $params The `tools/call` params: `name` and `arguments`.
     * @return array The MCP tool result (`content` + `isError`).
     */
    private function toolsCall(array $params, ?AdminContext $adminContext): array
    {
        $name = $params['name'] ?? null;
        if (!is_string($name) || $name === '') {
            throw new McpException('Invalid params: missing tool name', self::INVALID_PARAMS);
        }

        $arguments = $params['arguments'] ?? [];
        if (!is_array($arguments)) {
            throw new McpException('Invalid params: arguments must be an object', self::INVALID_PARAMS);
        }

        $tool = $this->toolRegistry->get($name);
        if ($this->isDisabledWriteTool($tool)) {
            throw new McpException(
                sprintf('Tool "%s" is disabled on this server (mcp/general/enable_write_tools is off).', $name),
                self::METHOD_NOT_FOUND
            );
        }
        $this->assertToolAllowed($tool, $adminContext);

        $startedAt = microtime(true);

        try {
            // Context-aware tools answer differently for admins (the "two storefronts"
            // pattern) — they get the resolved identity; plain tools stay identity-blind.
            $data = $tool instanceof ContextAwareToolInterface
                ? $tool->executeWithContext($arguments, $adminContext)
                : $tool->execute($arguments);

            $this->logger->info(sprintf(
                'tools/call "%s" succeeded in %.3fs',
                $name,
                microtime(true) - $startedAt
            ));

            if ($tool instanceof WriteToolInterface) {
                // Audit trail for data-modifying calls: who did it and what was created.
                // The result carries catalog data (ids/SKUs), not customer personal data,
                // so logging it at info level doesn't violate the module's logging rules.
                $this->logger->info(sprintf(
                    'write tool "%s" executed by admin #%d: %s',
                    $name,
                    $adminContext?->getAdminId() ?? 0,
                    json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                ));
            }

            return [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ],
                ],
                'isError' => false,
            ];
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf(
                'tools/call "%s" failed in %.3fs: %s',
                $name,
                microtime(true) - $startedAt,
                $e->getMessage()
            ));

            return [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $e->getMessage(),
                    ],
                ],
                'isError' => true,
            ];
        }
    }

    /**
     * @return array The `prompts/list` result: every registered prompt with its argument
     *     declarations. Prompts are plain instruction templates — no ACL filtering; the
     *     tools they reference stay gated at tools/call time.
     */
    private function promptsList(): array
    {
        $prompts = [];
        foreach ($this->promptRegistry->getAll() as $prompt) {
            $prompts[] = [
                'name' => $prompt->getName(),
                'description' => $prompt->getDescription(),
                'arguments' => $prompt->getArguments(),
            ];
        }

        return ['prompts' => $prompts];
    }

    /**
     * Executes a `prompts/get` request. Unlike tools, a prompt has no business logic that
     * could fail mid-flight — rendering errors are argument problems, so they map to
     * INVALID_PARAMS protocol errors rather than an isError result.
     *
     * @param array $params The `prompts/get` params: `name` and optional `arguments`.
     * @return array The MCP prompt result (`description` + `messages`).
     */
    private function promptsGet(array $params): array
    {
        $name = $params['name'] ?? null;
        if (!is_string($name) || $name === '') {
            throw new McpException('Invalid params: missing prompt name', self::INVALID_PARAMS);
        }

        $arguments = $params['arguments'] ?? [];
        if (!is_array($arguments)) {
            throw new McpException('Invalid params: arguments must be an object', self::INVALID_PARAMS);
        }

        $prompt = $this->promptRegistry->get($name);

        try {
            $messages = $prompt->render($arguments);
        } catch (\InvalidArgumentException $e) {
            throw new McpException(sprintf('Invalid params: %s', $e->getMessage()), self::INVALID_PARAMS);
        }

        return [
            'description' => $prompt->getDescription(),
            'messages' => $messages,
        ];
    }

    /**
     * Whether this tool is a write tool that is currently switched off by configuration.
     */
    private function isDisabledWriteTool(ToolInterface $tool): bool
    {
        return $tool instanceof WriteToolInterface && !$this->config->isWriteToolsEnabled();
    }

    /**
     * @throws UnauthorizedException if the tool requires a resource and no identity was resolved.
     * @throws ForbiddenException if the tool requires a resource this identity doesn't have.
     */
    private function assertToolAllowed(ToolInterface $tool, ?AdminContext $adminContext): void
    {
        $required = $tool->getRequiredAclResource();
        if ($required === null) {
            // Public tool. With the public-tools switch off, anonymous calls are rejected
            // with the same 401 an ACL-gated tool produces — that's what triggers an
            // OAuth-aware client's login flow. Authenticated callers pass regardless of
            // their ACL: the switch gates anonymity, not data sensitivity.
            if ($adminContext === null && !$this->config->isPublicToolsEnabled()) {
                throw new UnauthorizedException(sprintf(
                    'Tool "%s" requires authentication (anonymous access is disabled on this server).',
                    $tool->getName()
                ));
            }
            return;
        }

        if ($adminContext === null) {
            throw new UnauthorizedException(sprintf('Tool "%s" requires authentication.', $tool->getName()));
        }

        if (!$adminContext->hasAclResource($required)) {
            throw new ForbiddenException(
                sprintf('Tool "%s" requires the "%s" ACL resource.', $tool->getName(), $required)
            );
        }
    }
}
