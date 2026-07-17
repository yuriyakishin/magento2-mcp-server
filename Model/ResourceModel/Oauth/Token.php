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
     * Looks up a row id by its access token value.
     *
     * @param string $accessToken Access token value to look up.
     */
    public function getIdByAccessToken(string $accessToken): ?int
    {
        return $this->getIdByColumn('access_token', $accessToken);
    }

    /**
     * Looks up a row id by its refresh token value.
     *
     * @param string $refreshToken Refresh token value to look up.
     */
    public function getIdByRefreshToken(string $refreshToken): ?int
    {
        return $this->getIdByColumn('refresh_token', $refreshToken);
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
