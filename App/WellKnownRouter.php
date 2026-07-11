<?php

declare(strict_types=1);

namespace Yu\McpServer\App;

use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\RouterInterface;
use Yu\McpServer\Controller\WellKnown\OauthAuthorizationServer;
use Yu\McpServer\Controller\WellKnown\OauthProtectedResource;

/**
 * Serves the RFC 8615 `/.well-known/...` discovery documents required by the MCP
 * Authorization spec (RFC 8414 / RFC 9728).
 *
 * Standard Magento routing can't reach these paths: `oauth-authorization-server` and
 * `oauth-protected-resource` contain hyphens, which are valid in a URL segment but not in a
 * PHP namespace segment, so no `Controller/<Folder>/Index.php` naming scheme can match them.
 * This router matches the literal request path and instantiates the target controller
 * directly, bypassing the folder-name convention.
 */
class WellKnownRouter implements RouterInterface
{
    /**
     * Both the bare form and the path-aware form (with the `/mcp` suffix) must resolve:
     * per RFC 9728 §3.1 / RFC 8414 §3.1, when the resource identifier carries a path
     * component (ours is `/mcp`), clients insert the well-known suffix between host and
     * path — claude.ai requests `/.well-known/oauth-protected-resource/mcp`, and a 404
     * there silently aborts its whole OAuth discovery, so no login form ever appears.
     */
    private const ROUTES = [
        '.well-known/oauth-authorization-server' => OauthAuthorizationServer::class,
        '.well-known/oauth-authorization-server/mcp' => OauthAuthorizationServer::class,
        '.well-known/oauth-protected-resource' => OauthProtectedResource::class,
        '.well-known/oauth-protected-resource/mcp' => OauthProtectedResource::class,
    ];

    public function __construct(
        private readonly ActionFactory $actionFactory
    ) {
    }

    /**
     * @return ActionInterface|null The matched controller, or null to let other routers try.
     */
    public function match(RequestInterface $request): ?ActionInterface
    {
        $path = trim($request->getPathInfo(), '/');
        if (!isset(self::ROUTES[$path])) {
            return null;
        }

        return $this->actionFactory->create(self::ROUTES[$path]);
    }
}
