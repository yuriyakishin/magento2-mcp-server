<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\ResourceModel\Oauth;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Client extends AbstractDb
{
    /**
     * Binds this resource model to the mcp_oauth_client table and its primary key.
     */
    protected function _construct()
    {
        $this->_init('mcp_oauth_client', 'entity_id');
    }

    /**
     * Looks up a row id by its public client_id value.
     */
    public function getIdByClientId(string $clientId): ?int
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getMainTable(), 'entity_id')
            ->where('client_id = ?', $clientId);

        $entityId = $connection->fetchOne($select);

        return $entityId !== false ? (int) $entityId : null;
    }
}
