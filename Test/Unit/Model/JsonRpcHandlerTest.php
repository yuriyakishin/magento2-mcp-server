<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Yu\McpServer\Exception\UnauthorizedException;
use Yu\McpServer\Model\Auth\AdminContext;
use Yu\McpServer\Model\Config;
use Yu\McpServer\Model\ContextAwareToolInterface;
use Yu\McpServer\Model\JsonRpcHandler;
use Yu\McpServer\Model\PromptInterface;
use Yu\McpServer\Model\PromptRegistry;
use Yu\McpServer\Model\ToolInterface;
use Yu\McpServer\Model\ToolRegistry;
use Yu\McpServer\Model\WriteToolInterface;

class JsonRpcHandlerTest extends TestCase
{
    /**
     * Builds a handler with the write-tools flag mocked to the given state.
     */
    private function makeHandler(
        ToolRegistry $registry,
        bool $writeToolsEnabled = false,
        ?PromptRegistry $promptRegistry = null,
        bool $publicToolsEnabled = true
    ): JsonRpcHandler {
        $config = $this->createMock(Config::class);
        $config->method('isWriteToolsEnabled')->willReturn($writeToolsEnabled);
        $config->method('isPublicToolsEnabled')->willReturn($publicToolsEnabled);

        return new JsonRpcHandler(
            $registry,
            $config,
            $this->createMock(LoggerInterface::class),
            $promptRegistry ?? new PromptRegistry()
        );
    }

    /**
     * Builds a stub prompt whose render() echoes the received period argument back.
     */
    private function makePrompt(string $name): PromptInterface
    {
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->method('getName')->willReturn($name);
        $prompt->method('getDescription')->willReturn('Test prompt');
        $prompt->method('getArguments')->willReturn([
            ['name' => 'period', 'description' => 'Period', 'required' => false],
        ]);
        $prompt->method('render')->willReturnCallback(
            static fn (array $arguments) => [
                [
                    'role' => 'user',
                    'content' => ['type' => 'text', 'text' => 'period=' . ($arguments['period'] ?? 'default')],
                ],
            ]
        );

        return $prompt;
    }

    /**
     * initialize must echo the client's protocol version back when the server supports
     * that revision — answering with a fixed old version makes modern clients treat the
     * server as incompatible and drop the connection right after OAuth.
     */
    public function testInitializeEchoesSupportedRequestedProtocolVersion(): void
    {
        $handler = $this->makeHandler(new ToolRegistry([]));

        foreach (['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'] as $version) {
            $result = $handler->handle([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => ['protocolVersion' => $version],
            ]);

            $this->assertSame($version, $result['result']['protocolVersion']);
        }
    }

    /**
     * An unknown requested version (or none at all) gets the latest supported revision.
     */
    public function testInitializeFallsBackToLatestSupportedProtocolVersion(): void
    {
        $handler = $this->makeHandler(new ToolRegistry([]));

        foreach ([['protocolVersion' => '2099-01-01'], []] as $params) {
            $result = $handler->handle([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => $params,
            ]);

            $this->assertSame('2025-11-25', $result['result']['protocolVersion']);
        }
    }

    /**
     * A message with no "id" field is a notification and must get no response body.
     */
    public function testNotificationReturnsNoResponse(): void
    {
        $handler = $this->makeHandler(new ToolRegistry([]));

        $result = $handler->handle(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

        $this->assertNull($result);
    }

    /**
     * An unrecognized JSON-RPC method must produce a protocol-level error, not an isError result.
     */
    public function testUnknownMethodReturnsJsonRpcError(): void
    {
        $handler = $this->makeHandler(new ToolRegistry([]));

        $result = $handler->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'unknown/method']);

        $this->assertSame(1, $result['id']);
        $this->assertArrayHasKey('error', $result);
        $this->assertSame(-32601, $result['error']['code']);
    }

    /**
     * A tool exception must be converted into an `isError: true` result, not a JSON-RPC error.
     */
    public function testToolExecutionErrorReturnsIsErrorTrue(): void
    {
        $tool = $this->createMock(ToolInterface::class);
        $tool->method('execute')->willThrowException(new \RuntimeException('Something went wrong.'));

        $handler = $this->makeHandler(
            new ToolRegistry(['failing_tool' => $tool])
        );

        $result = $handler->handle([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => 'failing_tool', 'arguments' => []],
        ]);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertTrue($result['result']['isError']);
        $this->assertSame('Something went wrong.', $result['result']['content'][0]['text']);
    }

    /**
     * A successful tool call must return its data JSON-encoded with `isError: false`.
     */
    public function testSuccessfulToolsCallReturnsResult(): void
    {
        $tool = $this->createMock(ToolInterface::class);
        $tool->method('execute')->willReturn(['answer' => 42]);

        $handler = $this->makeHandler(
            new ToolRegistry(['ok_tool' => $tool])
        );

        $result = $handler->handle([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => ['name' => 'ok_tool', 'arguments' => []],
        ]);

        $this->assertFalse($result['result']['isError']);
        $this->assertSame(
            json_encode(['answer' => 42], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $result['result']['content'][0]['text']
        );
    }

    /**
     * A public tool (no required ACL resource) must be callable anonymously.
     */
    public function testAnonymousAccessToPublicToolSucceeds(): void
    {
        $tool = $this->createMock(ToolInterface::class);
        $tool->method('getRequiredAclResource')->willReturn(null);
        $tool->method('execute')->willReturn(['ok' => true]);

        $handler = $this->makeHandler(
            new ToolRegistry(['public_tool' => $tool])
        );

        $result = $handler->handle([
            'jsonrpc' => '2.0',
            'id' => 4,
            'method' => 'tools/call',
            'params' => ['name' => 'public_tool', 'arguments' => []],
        ], null);

        $this->assertFalse($result['result']['isError']);
    }

    /**
     * Calling a restricted tool with no AdminContext at all must throw UnauthorizedException,
     * not return a JSON-RPC error body or a tool-execution isError. It has to propagate as a
     * real exception so Controller/Index/Index.php can turn it into an HTTP 401 with
     * WWW-Authenticate — the signal OAuth-aware clients need to trigger their login flow.
     */
    public function testMissingContextOnRestrictedToolThrowsUnauthorized(): void
    {
        $tool = $this->createMock(ToolInterface::class);
        $tool->method('getRequiredAclResource')->willReturn('Magento_Sales::sales');

        $handler = $this->makeHandler(
            new ToolRegistry(['restricted_tool' => $tool])
        );

        $this->expectException(UnauthorizedException::class);

        $handler->handle([
            'jsonrpc' => '2.0',
            'id' => 5,
            'method' => 'tools/call',
            'params' => ['name' => 'restricted_tool', 'arguments' => []],
        ], null);
    }

    /**
     * A resolved identity without the required ACL resource must fail as Forbidden.
     */
    public function testContextWithoutRequiredResourceReturnsForbidden(): void
    {
        $tool = $this->createMock(ToolInterface::class);
        $tool->method('getRequiredAclResource')->willReturn('Magento_Sales::sales');

        $handler = $this->makeHandler(
            new ToolRegistry(['restricted_tool' => $tool])
        );

        $adminContext = new AdminContext(1, ['Magento_Catalog::catalog']);

        $result = $handler->handle([
            'jsonrpc' => '2.0',
            'id' => 6,
            'method' => 'tools/call',
            'params' => ['name' => 'restricted_tool', 'arguments' => []],
        ], $adminContext);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame(-32002, $result['error']['code']);
    }

    /**
     * A resolved identity that has the required ACL resource must succeed.
     */
    public function testContextWithRequiredResourceSucceeds(): void
    {
        $tool = $this->createMock(ToolInterface::class);
        $tool->method('getRequiredAclResource')->willReturn('Magento_Sales::sales');
        $tool->method('execute')->willReturn(['orders' => []]);

        $handler = $this->makeHandler(
            new ToolRegistry(['restricted_tool' => $tool])
        );

        $adminContext = new AdminContext(1, ['Magento_Sales::sales']);

        $result = $handler->handle([
            'jsonrpc' => '2.0',
            'id' => 7,
            'method' => 'tools/call',
            'params' => ['name' => 'restricted_tool', 'arguments' => []],
        ], $adminContext);

        $this->assertFalse($result['result']['isError']);
    }

    /**
     * tools/list must include restricted tools even for anonymous callers. Access control
     * happens at tools/call time, not here — see JsonRpcHandler::toolsList() for why: an
     * anonymous caller who can't even see a restricted tool never attempts to call it, so
     * never gets the 401 that would trigger an OAuth-aware client's login flow.
     */
    public function testToolsListIncludesRestrictedToolsForAnonymousCallers(): void
    {
        $publicTool = $this->createMock(ToolInterface::class);
        $publicTool->method('getName')->willReturn('public_tool');
        $publicTool->method('getRequiredAclResource')->willReturn(null);

        $restrictedTool = $this->createMock(ToolInterface::class);
        $restrictedTool->method('getName')->willReturn('restricted_tool');
        $restrictedTool->method('getRequiredAclResource')->willReturn('Magento_Sales::sales');

        $handler = $this->makeHandler(
            new ToolRegistry(['public_tool' => $publicTool, 'restricted_tool' => $restrictedTool])
        );

        $result = $handler->handle(['jsonrpc' => '2.0', 'id' => 8, 'method' => 'tools/list'], null);

        $names = array_column($result['result']['tools'], 'name');
        $this->assertSame(['public_tool', 'restricted_tool'], $names);
    }

    /**
     * With the write-tools flag off, a write tool must not appear in tools/list at all —
     * unlike ACL-restricted read tools, no login flow could ever unlock it.
     */
    public function testWriteToolHiddenFromToolsListWhenDisabled(): void
    {
        $readTool = $this->createMock(ToolInterface::class);
        $readTool->method('getName')->willReturn('read_tool');

        $writeTool = $this->createMock(WriteToolInterface::class);
        $writeTool->method('getName')->willReturn('write_tool');

        $handler = $this->makeHandler(
            new ToolRegistry(['read_tool' => $readTool, 'write_tool' => $writeTool]),
            writeToolsEnabled: false
        );

        $result = $handler->handle(['jsonrpc' => '2.0', 'id' => 9, 'method' => 'tools/list'], null);

        $this->assertSame(['read_tool'], array_column($result['result']['tools'], 'name'));
    }

    /**
     * With the write-tools flag on, write tools are listed like any other tool.
     */
    public function testWriteToolListedWhenEnabled(): void
    {
        $writeTool = $this->createMock(WriteToolInterface::class);
        $writeTool->method('getName')->willReturn('write_tool');

        $handler = $this->makeHandler(
            new ToolRegistry(['write_tool' => $writeTool]),
            writeToolsEnabled: true
        );

        $result = $handler->handle(['jsonrpc' => '2.0', 'id' => 10, 'method' => 'tools/list'], null);

        $this->assertSame(['write_tool'], array_column($result['result']['tools'], 'name'));
    }

    /**
     * Calling a write tool while the flag is off must fail as a protocol-level error
     * (method-not-found code), even for a fully authorized admin.
     */
    public function testWriteToolCallRejectedWhenDisabled(): void
    {
        $writeTool = $this->createMock(WriteToolInterface::class);
        $writeTool->method('getName')->willReturn('write_tool');
        $writeTool->method('getRequiredAclResource')->willReturn('Magento_Catalog::products');
        $writeTool->expects($this->never())->method('execute');

        $handler = $this->makeHandler(
            new ToolRegistry(['write_tool' => $writeTool]),
            writeToolsEnabled: false
        );

        $result = $handler->handle([
            'jsonrpc' => '2.0',
            'id' => 11,
            'method' => 'tools/call',
            'params' => ['name' => 'write_tool', 'arguments' => []],
        ], new AdminContext(1, ['Magento_Backend::all']));

        $this->assertArrayHasKey('error', $result);
        $this->assertSame(-32601, $result['error']['code']);
    }

    /**
     * With the flag on, a write tool call still goes through the normal ACL gate and
     * succeeds for an admin holding the required resource.
     */
    public function testWriteToolCallSucceedsWhenEnabledAndAuthorized(): void
    {
        $writeTool = $this->createMock(WriteToolInterface::class);
        $writeTool->method('getName')->willReturn('write_tool');
        $writeTool->method('getRequiredAclResource')->willReturn('Magento_Catalog::products');
        $writeTool->method('execute')->willReturn(['product' => ['id' => 1]]);

        $handler = $this->makeHandler(
            new ToolRegistry(['write_tool' => $writeTool]),
            writeToolsEnabled: true
        );

        $result = $handler->handle([
            'jsonrpc' => '2.0',
            'id' => 12,
            'method' => 'tools/call',
            'params' => ['name' => 'write_tool', 'arguments' => []],
        ], new AdminContext(1, ['Magento_Catalog::products']));

        $this->assertFalse($result['result']['isError']);
    }

    /**
     * With the public-tools switch off, an anonymous call to a public tool must throw
     * UnauthorizedException (→ HTTP 401) so OAuth-aware clients trigger their login flow.
     */
    public function testAnonymousPublicToolCallRejectedWhenPublicToolsDisabled(): void
    {
        $tool = $this->createMock(ToolInterface::class);
        $tool->method('getName')->willReturn('public_tool');
        $tool->method('getRequiredAclResource')->willReturn(null);
        $tool->expects($this->never())->method('execute');

        $handler = $this->makeHandler(
            new ToolRegistry(['public_tool' => $tool]),
            publicToolsEnabled: false
        );

        $this->expectException(UnauthorizedException::class);

        $handler->handle([
            'jsonrpc' => '2.0',
            'id' => 30,
            'method' => 'tools/call',
            'params' => ['name' => 'public_tool', 'arguments' => []],
        ], null);
    }

    /**
     * The public-tools switch gates anonymity, not data: any authenticated admin may call
     * public tools even with the switch off, regardless of ACL resources.
     */
    public function testAuthenticatedCallerMayCallPublicToolWhenPublicToolsDisabled(): void
    {
        $tool = $this->createMock(ToolInterface::class);
        $tool->method('getName')->willReturn('public_tool');
        $tool->method('getRequiredAclResource')->willReturn(null);
        $tool->method('execute')->willReturn(['ok' => true]);

        $handler = $this->makeHandler(
            new ToolRegistry(['public_tool' => $tool]),
            publicToolsEnabled: false
        );

        $result = $handler->handle([
            'jsonrpc' => '2.0',
            'id' => 31,
            'method' => 'tools/call',
            'params' => ['name' => 'public_tool', 'arguments' => []],
        ], new AdminContext(1, []));

        $this->assertFalse($result['result']['isError']);
    }

    /**
     * Context-aware tools must receive the caller's identity through executeWithContext(),
     * not the identity-blind execute().
     */
    public function testContextAwareToolReceivesAdminContext(): void
    {
        $adminContext = new AdminContext(7, ['Magento_Catalog::products']);

        $tool = $this->createMock(ContextAwareToolInterface::class);
        $tool->method('getName')->willReturn('aware_tool');
        $tool->method('getRequiredAclResource')->willReturn(null);
        $tool->expects($this->never())->method('execute');
        $tool->expects($this->once())->method('executeWithContext')
            ->with(['q' => 1], $adminContext)
            ->willReturn(['ok' => true]);

        $handler = $this->makeHandler(new ToolRegistry(['aware_tool' => $tool]));

        $result = $handler->handle([
            'jsonrpc' => '2.0',
            'id' => 32,
            'method' => 'tools/call',
            'params' => ['name' => 'aware_tool', 'arguments' => ['q' => 1]],
        ], $adminContext);

        $this->assertFalse($result['result']['isError']);
    }

    /**
     * initialize must advertise the prompts capability alongside tools.
     */
    public function testInitializeAdvertisesPromptsCapability(): void
    {
        $handler = $this->makeHandler(new ToolRegistry([]));

        $result = $handler->handle([
            'jsonrpc' => '2.0',
            'id' => 20,
            'method' => 'initialize',
            'params' => [],
        ]);

        $this->assertArrayHasKey('prompts', $result['result']['capabilities']);
    }

    /**
     * prompts/list must return every registered prompt with its argument declarations,
     * anonymously — prompts are instruction templates, not data access.
     */
    public function testPromptsListReturnsRegisteredPrompts(): void
    {
        $handler = $this->makeHandler(
            new ToolRegistry([]),
            promptRegistry: new PromptRegistry(['test_prompt' => $this->makePrompt('test_prompt')])
        );

        $result = $handler->handle(['jsonrpc' => '2.0', 'id' => 21, 'method' => 'prompts/list'], null);

        $this->assertCount(1, $result['result']['prompts']);
        $this->assertSame('test_prompt', $result['result']['prompts'][0]['name']);
        $this->assertSame('period', $result['result']['prompts'][0]['arguments'][0]['name']);
    }

    /**
     * prompts/get must render the prompt with the supplied arguments into MCP messages.
     */
    public function testPromptsGetRendersMessages(): void
    {
        $handler = $this->makeHandler(
            new ToolRegistry([]),
            promptRegistry: new PromptRegistry(['test_prompt' => $this->makePrompt('test_prompt')])
        );

        $result = $handler->handle([
            'jsonrpc' => '2.0',
            'id' => 22,
            'method' => 'prompts/get',
            'params' => ['name' => 'test_prompt', 'arguments' => ['period' => 'today']],
        ], null);

        $this->assertSame('Test prompt', $result['result']['description']);
        $this->assertSame('period=today', $result['result']['messages'][0]['content']['text']);
    }

    /**
     * An unknown prompt name and a missing name must both fail as INVALID_PARAMS
     * protocol errors — a prompt has no business logic that could produce isError.
     */
    public function testPromptsGetRejectsUnknownAndMissingName(): void
    {
        $handler = $this->makeHandler(
            new ToolRegistry([]),
            promptRegistry: new PromptRegistry(['test_prompt' => $this->makePrompt('test_prompt')])
        );

        foreach ([['name' => 'nope'], []] as $params) {
            $result = $handler->handle([
                'jsonrpc' => '2.0',
                'id' => 23,
                'method' => 'prompts/get',
                'params' => $params,
            ], null);

            $this->assertArrayHasKey('error', $result);
            $this->assertSame(-32602, $result['error']['code']);
        }
    }

    /**
     * A render-time InvalidArgumentException (bad argument value) must surface as an
     * INVALID_PARAMS protocol error, not leak as an unhandled exception.
     */
    public function testPromptsGetMapsRenderErrorToInvalidParams(): void
    {
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->method('getName')->willReturn('strict_prompt');
        $prompt->method('getDescription')->willReturn('Strict');
        $prompt->method('getArguments')->willReturn([]);
        $prompt->method('render')->willThrowException(new \InvalidArgumentException('bad period'));

        $handler = $this->makeHandler(
            new ToolRegistry([]),
            promptRegistry: new PromptRegistry(['strict_prompt' => $prompt])
        );

        $result = $handler->handle([
            'jsonrpc' => '2.0',
            'id' => 24,
            'method' => 'prompts/get',
            'params' => ['name' => 'strict_prompt', 'arguments' => ['period' => '']],
        ], null);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame(-32602, $result['error']['code']);
        $this->assertStringContainsString('bad period', $result['error']['message']);
    }

    /**
     * A batch containing an anonymous call to a restricted tool must abort the whole batch
     * by propagating UnauthorizedException, not silently drop that one message's response.
     */
    public function testHandleBatchPropagatesUnauthorizedFromAnyMessage(): void
    {
        $tool = $this->createMock(ToolInterface::class);
        $tool->method('getRequiredAclResource')->willReturn('Magento_Sales::sales');

        $handler = $this->makeHandler(
            new ToolRegistry(['restricted_tool' => $tool])
        );

        $this->expectException(UnauthorizedException::class);

        $handler->handleBatch([
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
            [
                'jsonrpc' => '2.0',
                'id' => 2,
                'method' => 'tools/call',
                'params' => ['name' => 'restricted_tool', 'arguments' => []],
            ],
        ], null);
    }
}
