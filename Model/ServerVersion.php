<?php

declare(strict_types=1);

namespace Yu\McpServer\Model;

use Composer\InstalledVersions;

/**
 * Version reported in the MCP `initialize` handshake (serverInfo.version): the installed
 * Composer package version, or "dev" when the module is not installed through Composer
 * (e.g. copied into app/code).
 */
class ServerVersion
{
    public const PACKAGE_NAME = 'yu-dev/module-mcp-server';
    private const FALLBACK = 'dev';

    /**
     * Installed version of this module, without a leading "v".
     */
    public function get(): string
    {
        if (!class_exists(InstalledVersions::class)
            || !InstalledVersions::isInstalled(self::PACKAGE_NAME)
        ) {
            return self::FALLBACK;
        }

        $version = (string)InstalledVersions::getPrettyVersion(self::PACKAGE_NAME);

        return $version !== '' ? ltrim($version, 'vV') : self::FALLBACK;
    }
}
