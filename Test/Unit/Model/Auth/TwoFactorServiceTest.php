<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Auth;

use Magento\Framework\DataObject;
use Magento\Framework\DataObjectFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\TwoFactorAuth\Api\ProviderInterface;
use Magento\TwoFactorAuth\Api\TfaInterface;
use Magento\TwoFactorAuth\Model\Provider\Engine\Google;
use Magento\User\Model\ResourceModel\User as UserResource;
use Magento\User\Model\User;
use Magento\User\Model\UserFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\Auth\TwoFactorService;

class TwoFactorServiceTest extends TestCase
{
    private ModuleManager|MockObject $moduleManager;
    private TfaInterface|MockObject $tfa;
    private Google|MockObject $googleEngine;
    private UserFactory|MockObject $userFactory;
    private UserResource|MockObject $userResource;
    private DataObjectFactory|MockObject $dataObjectFactory;
    private TwoFactorService $service;

    protected function setUp(): void
    {
        $this->moduleManager = $this->createMock(ModuleManager::class);
        $this->tfa = $this->createMock(TfaInterface::class);
        $this->googleEngine = $this->createMock(Google::class);
        $this->userFactory = $this->createMock(UserFactory::class);
        $this->userResource = $this->createMock(UserResource::class);
        $this->dataObjectFactory = $this->createMock(DataObjectFactory::class);

        $this->service = new TwoFactorService(
            $this->moduleManager,
            $this->tfa,
            $this->googleEngine,
            $this->userFactory,
            $this->userResource,
            $this->dataObjectFactory
        );
    }

    /**
     * Builds a provider mock with the given code and active state.
     */
    private function mockProvider(string $code, bool $active): ProviderInterface|MockObject
    {
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('getCode')->willReturn($code);
        $provider->method('isActive')->willReturn($active);

        return $provider;
    }

    /**
     * With Magento_TwoFactorAuth disabled, no code must be required and the vendor
     * services must never be touched.
     */
    public function testCheckNotRequiredWhenModuleDisabled(): void
    {
        $this->moduleManager->method('isEnabled')->with('Magento_TwoFactorAuth')->willReturn(false);
        $this->tfa->expects($this->never())->method('getUserProviders');

        $this->assertSame(TwoFactorService::RESULT_NOT_REQUIRED, $this->service->check(42));
    }

    /**
     * With no providers enforced for the user, no code must be required.
     */
    public function testCheckNotRequiredWithoutProviders(): void
    {
        $this->moduleManager->method('isEnabled')->willReturn(true);
        $this->tfa->method('isEnabled')->willReturn(true);
        $this->tfa->method('getUserProviders')->with(42)->willReturn([]);

        $this->assertSame(TwoFactorService::RESULT_NOT_REQUIRED, $this->service->check(42));
    }

    /**
     * A user with an activated authenticator app must be challenged for a code.
     */
    public function testCheckRequiredWithActiveGoogleProvider(): void
    {
        $this->moduleManager->method('isEnabled')->willReturn(true);
        $this->tfa->method('isEnabled')->willReturn(true);
        $this->tfa->method('getUserProviders')->willReturn([$this->mockProvider(Google::CODE, true)]);

        $this->assertSame(TwoFactorService::RESULT_REQUIRED, $this->service->check(42));
    }

    /**
     * A user who hasn't finished setting up the authenticator app must be reported as
     * not configured, never waved through.
     */
    public function testCheckNotConfiguredWithInactiveGoogleProvider(): void
    {
        $this->moduleManager->method('isEnabled')->willReturn(true);
        $this->tfa->method('isEnabled')->willReturn(true);
        $this->tfa->method('getUserProviders')->willReturn([$this->mockProvider(Google::CODE, false)]);

        $this->assertSame(TwoFactorService::RESULT_NOT_CONFIGURED, $this->service->check(42));
    }

    /**
     * A user enforced onto a provider this form can't drive must be reported as
     * unsupported, never waved through.
     */
    public function testCheckUnsupportedWithoutGoogleProvider(): void
    {
        $this->moduleManager->method('isEnabled')->willReturn(true);
        $this->tfa->method('isEnabled')->willReturn(true);
        $this->tfa->method('getUserProviders')->willReturn([$this->mockProvider('duo_security', true)]);

        $this->assertSame(TwoFactorService::RESULT_UNSUPPORTED, $this->service->check(42));
    }

    /**
     * An empty code must fail without loading anything.
     */
    public function testVerifyCodeRejectsEmptyCode(): void
    {
        $this->userFactory->expects($this->never())->method('create');

        $this->assertFalse($this->service->verifyCode(42, ''));
    }

    /**
     * A code for a user id that no longer resolves to an admin must fail.
     */
    public function testVerifyCodeRejectsUnknownUser(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(null);
        $this->userFactory->method('create')->willReturn($user);
        $this->googleEngine->expects($this->never())->method('verify');

        $this->assertFalse($this->service->verifyCode(42, '123456'));
    }

    /**
     * A valid code must delegate to the vendor Google engine and pass its verdict through.
     */
    public function testVerifyCodeDelegatesToGoogleEngine(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(42);
        $this->userFactory->method('create')->willReturn($user);

        $request = new DataObject(['tfa_code' => '123456']);
        $this->dataObjectFactory->method('create')
            ->with(['data' => ['tfa_code' => '123456']])
            ->willReturn($request);
        $this->googleEngine->expects($this->once())
            ->method('verify')
            ->with($user, $request)
            ->willReturn(true);

        $this->assertTrue($this->service->verifyCode(42, '123456'));
    }

    /**
     * An engine exception (e.g. a missing TOTP secret) must read as a wrong code, never
     * as a skipped check.
     */
    public function testVerifyCodeReturnsFalseWhenEngineThrows(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(42);
        $this->userFactory->method('create')->willReturn($user);
        $this->dataObjectFactory->method('create')->willReturn(new DataObject(['tfa_code' => '123456']));
        $this->googleEngine->method('verify')
            ->willThrowException(new NoSuchEntityException(__('Secret for user with ID#42 was not found')));

        $this->assertFalse($this->service->verifyCode(42, '123456'));
    }
}
