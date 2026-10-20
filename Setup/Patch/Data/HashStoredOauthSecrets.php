<?php

declare(strict_types=1);

namespace Yu\McpServer\Setup\Patch\Data;

use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Replaces OAuth secrets stored in plain text by earlier versions with their SHA-256
 * digest (see Model/Oauth/SecretHasher), so already connected clients keep working.
 */
class HashStoredOauthSecrets implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup
    ) {
    }

    /**
     * Hashes every stored token and authorization code in place.
     */
    public function apply(): self
    {
        $connection = $this->moduleDataSetup->getConnection();
        $connection->startSetup();

        $connection->update(
            $this->moduleDataSetup->getTable('mcp_oauth_token'),
            [
                'access_token' => new Expression('SHA2(access_token, 256)'),
                'refresh_token' => new Expression('SHA2(refresh_token, 256)'),
            ]
        );
        $connection->update(
            $this->moduleDataSetup->getTable('mcp_oauth_auth_code'),
            ['code' => new Expression('SHA2(code, 256)')]
        );

        $connection->endSetup();

        return $this;
    }

    /**
     * @inheritdoc
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
