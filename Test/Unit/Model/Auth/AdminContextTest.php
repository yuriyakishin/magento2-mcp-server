<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Auth;

use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\Auth\AdminContext;

class AdminContextTest extends TestCase
{
    /**
     * The constructor arguments must be exposed back via their getters.
     */
    public function testExposesAdminIdAndAllowedResources(): void
    {
        $context = new AdminContext(7, ['Magento_Sales::sales', 'Magento_Catalog::catalog']);

        $this->assertSame(7, $context->getAdminId());
        $this->assertSame(['Magento_Sales::sales', 'Magento_Catalog::catalog'], $context->getAllowedAclResources());
    }

    /**
     * hasAclResource() must be true only for resources actually in the allowed list.
     */
    public function testHasAclResourceReflectsAllowedList(): void
    {
        $context = new AdminContext(1, ['Magento_Sales::sales']);

        $this->assertTrue($context->hasAclResource('Magento_Sales::sales'));
        $this->assertFalse($context->hasAclResource('Magento_Catalog::catalog'));
    }

    /**
     * A role granted "All" access carries only the root resource Magento_Backend::all,
     * which must grant every specific resource (see AdminContext::ROOT_RESOURCE).
     */
    public function testRootResourceActsAsWildcard(): void
    {
        $context = new AdminContext(1, ['Magento_Backend::all']);

        $this->assertTrue($context->hasAclResource('Magento_Sales::sales'));
        $this->assertTrue($context->hasAclResource('Magento_Catalog::catalog'));
    }
}
