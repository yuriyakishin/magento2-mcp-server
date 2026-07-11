<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Auth;

/**
 * Immutable value object representing the admin identity behind an authenticated MCP request.
 */
class AdminContext
{
    /**
     * The ACL root resource id. A role granted "All" access stores only this single row
     * in authorization_rule — AclRetriever returns ['Magento_Backend::all'], not the
     * expanded per-resource list — so the root resource must be honored as a wildcard.
     */
    private const ROOT_RESOURCE = 'Magento_Backend::all';

    /**
     * @param string[] $allowedAclResources
     */
    public function __construct(
        private readonly int $adminId,
        private readonly array $allowedAclResources
    ) {
    }

    /**
     * The Magento admin user id this request is authenticated as.
     */
    public function getAdminId(): int
    {
        return $this->adminId;
    }

    /**
     * @return string[]
     */
    public function getAllowedAclResources(): array
    {
        return $this->allowedAclResources;
    }

    /**
     * Whether this admin's ACL rights include the given resource.
     */
    public function hasAclResource(string $resource): bool
    {
        return in_array(self::ROOT_RESOURCE, $this->allowedAclResources, true)
            || in_array($resource, $this->allowedAclResources, true);
    }
}
