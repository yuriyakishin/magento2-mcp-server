<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\ResourceModel\Oauth;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class AuthCode extends AbstractDb
{
    /**
     * Binds this resource model to the mcp_oauth_auth_code table and its primary key.
     */
    protected function _construct()
    {
        $this->_init('mcp_oauth_auth_code', 'entity_id');
    }

    /**
     * Looks up a row id by its authorization code value.
     */
    public function getIdByCode(string $code): ?int
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getMainTable(), 'entity_id')
            ->where('code = ?', $code);

        $entityId = $connection->fetchOne($select);

        return $entityId !== false ? (int) $entityId : null;
    }
}
