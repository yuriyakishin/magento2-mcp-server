<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\ResourceModel\Oauth;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Token extends AbstractDb
{
    /**
     * Binds this resource model to the mcp_oauth_token table and its primary key.
     */
    protected function _construct()
    {
        $this->_init('mcp_oauth_token', 'entity_id');
    }

    /**
     * Looks up a row id by its stored access token digest.
     *
     * @param string $accessToken SHA-256 digest of the access token.
     */
    public function getIdByAccessToken(string $accessToken): ?int
    {
        return $this->getIdByColumn('access_token', $accessToken);
    }

    /**
     * Looks up a row id by its stored refresh token digest.
     *
     * @param string $refreshToken SHA-256 digest of the refresh token.
     */
    public function getIdByRefreshToken(string $refreshToken): ?int
    {
        return $this->getIdByColumn('refresh_token', $refreshToken);
    }

    /**
     * Revokes every not-yet-revoked token pair of the given admin user.
     *
     * @param int $adminUserId Admin user whose tokens to revoke.
     * @param string $revokedAt GMT timestamp ("Y-m-d H:i:s") to record as the revocation time.
     * @return int Number of revoked token pairs.
     */
    public function revokeAllForAdmin(int $adminUserId, string $revokedAt): int
    {
        $connection = $this->getConnection();

        return $connection->update(
            $this->getMainTable(),
            ['revoked_at' => $revokedAt],
            [
                'admin_user_id = ?' => $adminUserId,
                'revoked_at IS NULL',
            ]
        );
    }

    /**
     * Looks up a row id by an arbitrary unique column value.
     *
     * @param string $column Column name to filter on.
     * @param string $value Value to match in that column.
     */
    private function getIdByColumn(string $column, string $value): ?int
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getMainTable(), 'entity_id')
            ->where($column . ' = ?', $value);

        $entityId = $connection->fetchOne($select);

        return $entityId !== false ? (int)$entityId : null;
    }
}
