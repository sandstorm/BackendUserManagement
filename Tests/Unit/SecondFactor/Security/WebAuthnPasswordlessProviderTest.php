<?php

namespace Sandstorm\BackendUserManagement\Tests\Unit\SecondFactor\Security;

use GuzzleHttp\Psr7\ServerRequest;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Http\HttpRequestHandlerInterface;
use Neos\Flow\Http\ServerRequestAttributes;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\Security\Account;
use Neos\Flow\Security\AccountRepository;
use Neos\Flow\Security\Authentication\AuthenticationProviderInterface;
use Neos\Flow\Security\Authentication\TokenInterface;
use Neos\Flow\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Sandstorm\BackendUserManagement\SecondFactor\Domain\AuthenticationStatus;
use Sandstorm\BackendUserManagement\SecondFactor\Security\Authentication\WebAuthnPasswordlessProvider;
use Sandstorm\BackendUserManagement\SecondFactor\Security\Token\WebAuthnPasswordlessToken;
use Sandstorm\BackendUserManagement\SecondFactor\Service\PasswordlessAssertionRejectedException;
use Sandstorm\BackendUserManagement\SecondFactor\Service\SecondFactorSessionStorageService;
use Sandstorm\BackendUserManagement\SecondFactor\Service\WebAuthnService;
use Webauthn\PublicKeyCredentialRequestOptions;

class WebAuthnPasswordlessProviderTest extends UnitTestCase
{
    private AuthenticationProviderInterface $provider;

    private WebAuthnService&MockObject $webAuthnService;

    private SecondFactorSessionStorageService&MockObject $sessionStorage;

    private LoggerInterface&MockObject $securityLogger;

    private AccountRepository&MockObject $accountRepository;

    private ServerRequest $httpRequest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = WebAuthnPasswordlessProvider::create('Sandstorm.BackendUserManagement:PasswordlessWebAuthn', []);

        $this->webAuthnService = $this->createMock(WebAuthnService::class);
        $this->webAuthnService->method('requestOptionsFromJson')
            ->willReturn(PublicKeyCredentialRequestOptions::create(random_bytes(32)));
        $this->sessionStorage = $this->createMock(SecondFactorSessionStorageService::class);
        $this->securityLogger = $this->createMock(LoggerInterface::class);

        $this->httpRequest = (new ServerRequest('POST', 'https://neos.example/neos/passwordless-webauthn/verify'))
            ->withAttribute(ServerRequestAttributes::CLIENT_IP, '203.0.113.7');
        $requestHandler = $this->createMock(HttpRequestHandlerInterface::class);
        $requestHandler->method('getHttpRequest')->willReturn($this->httpRequest);
        $bootstrap = $this->createMock(Bootstrap::class);
        $bootstrap->method('getActiveRequestHandler')->willReturn($requestHandler);

        $this->inject($this->provider, 'webAuthnService', $this->webAuthnService);
        $this->inject($this->provider, 'secondFactorSessionStorageService', $this->sessionStorage);
        $this->inject($this->provider, 'bootstrap', $bootstrap);
        $this->inject($this->provider, 'securityLogger', $this->securityLogger);
        $this->accountRepository = $this->createMock(AccountRepository::class);
        $this->inject($this->provider, 'accountRepository', $this->accountRepository);
        $this->inject($this->provider, 'persistenceManager', $this->createMock(PersistenceManagerInterface::class));
        $this->inject($this->provider, 'passwordlessLoginEnabled', true);
    }

    #[Test]
    public function authenticatesTheAccountOfAValidAssertion(): void
    {
        $account = $this->createMock(Account::class);
        // resets the failed-attempt counter, like Flow's password provider
        $account->expects(self::once())->method('authenticationAttempted')->with(TokenInterface::AUTHENTICATION_SUCCESSFUL);
        $this->accountRepository->expects(self::once())->method('update')->with($account);
        $this->sessionStorage->method('getValue')->willReturn('{"stored":"options"}');
        $this->webAuthnService->expects(self::once())->method('verifyPasswordlessAssertion')
            ->with('the-assertion', self::anything(), $this->httpRequest)
            ->willReturn($account);
        $this->sessionStorage->expects(self::once())->method('setAuthenticationStatus')->with(AuthenticationStatus::AUTHENTICATED);
        $this->securityLogger->expects(self::never())->method('warning');

        $token = $this->tokenWithAssertion('the-assertion');
        $this->provider->authenticate($token);

        self::assertSame(TokenInterface::AUTHENTICATION_SUCCESSFUL, $token->getAuthenticationStatus());
        self::assertSame($account, $token->getAccount());
    }

    #[Test]
    public function rejectsAndLogsAFailedVerification(): void
    {
        $this->sessionStorage->method('getValue')->willReturn('{"stored":"options"}');
        $this->webAuthnService->method('verifyPasswordlessAssertion')
            ->willThrowException(new \RuntimeException('Passkey belongs to an inactive account', 1793795361));
        $this->sessionStorage->expects(self::never())->method('setAuthenticationStatus');
        $this->securityLogger->expects(self::once())->method('warning')->with(
            self::stringContains('Passkey belongs to an inactive account (1793795361)'),
            ['clientIp' => '203.0.113.7']
        );

        $token = $this->tokenWithAssertion('the-assertion');
        $this->provider->authenticate($token);

        self::assertSame(TokenInterface::WRONG_CREDENTIALS, $token->getAuthenticationStatus());
        self::assertNull($token->getAccount());
    }

    #[Test]
    public function countsARejectedAssertionOnTheKnownAccount(): void
    {
        $account = $this->createMock(Account::class);
        $account->method('getAccountIdentifier')->willReturn('editor');
        $account->expects(self::once())->method('authenticationAttempted')->with(TokenInterface::WRONG_CREDENTIALS);
        $this->accountRepository->expects(self::once())->method('update')->with($account);
        $this->sessionStorage->method('getValue')->willReturn('{"stored":"options"}');
        $this->webAuthnService->method('verifyPasswordlessAssertion')
            ->willThrowException(new PasswordlessAssertionRejectedException($account, new \RuntimeException('bad signature')));
        $this->securityLogger->expects(self::once())->method('warning')->with(
            self::stringContains('bad signature'),
            ['clientIp' => '203.0.113.7', 'accountIdentifier' => 'editor']
        );

        $token = $this->tokenWithAssertion('the-assertion');
        $this->provider->authenticate($token);

        self::assertSame(TokenInterface::WRONG_CREDENTIALS, $token->getAuthenticationStatus());
        self::assertNull($token->getAccount());
    }

    #[Test]
    public function countsNothingWithoutAKnownAccount(): void
    {
        // unknown credential, inactive or non-backend account: nothing to count on (Flow counts only active accounts)
        $this->sessionStorage->method('getValue')->willReturn('{"stored":"options"}');
        $this->webAuthnService->method('verifyPasswordlessAssertion')
            ->willThrowException(new \RuntimeException('Unknown passkey credential', 1751200001));
        $this->accountRepository->expects(self::never())->method('update');

        $this->provider->authenticate($this->tokenWithAssertion('the-assertion'));
    }

    #[Test]
    public function theChallengeIsSingleUseEvenIfVerificationFails(): void
    {
        $this->sessionStorage->method('getValue')->willReturn('{"stored":"options"}');
        $this->webAuthnService->method('verifyPasswordlessAssertion')->willThrowException(new \RuntimeException('bad signature'));
        $this->sessionStorage->expects(self::once())->method('removeValue')
            ->with(SecondFactorSessionStorageService::SESSION_OBJECT_WEBAUTHN_PASSWORDLESS_OPTIONS);

        $this->provider->authenticate($this->tokenWithAssertion('the-assertion'));
    }

    #[Test]
    public function rejectsWithoutAChallengeInTheSession(): void
    {
        $this->sessionStorage->method('getValue')->willReturn(null);
        $this->webAuthnService->expects(self::never())->method('verifyPasswordlessAssertion');
        $this->securityLogger->expects(self::once())->method('warning')->with(self::stringContains('no passwordless login in progress'));

        $token = $this->tokenWithAssertion('the-assertion');
        $this->provider->authenticate($token);

        self::assertSame(TokenInterface::WRONG_CREDENTIALS, $token->getAuthenticationStatus());
    }

    #[Test]
    public function rejectsWhilePasswordlessLoginIsDisabled(): void
    {
        $this->inject($this->provider, 'passwordlessLoginEnabled', false);
        $this->sessionStorage->method('getValue')->willReturn('{"stored":"options"}');
        $this->webAuthnService->expects(self::never())->method('verifyPasswordlessAssertion');
        $this->securityLogger->expects(self::once())->method('warning')->with(self::stringContains('disabled'));

        $token = $this->tokenWithAssertion('the-assertion');
        $this->provider->authenticate($token);

        self::assertSame(TokenInterface::WRONG_CREDENTIALS, $token->getAuthenticationStatus());
    }

    #[Test]
    public function tokenPicksUpTheNamespacedAssertionOfAPostRequest(): void
    {
        $token = $this->tokenWithAssertion('the-assertion');

        self::assertSame(TokenInterface::AUTHENTICATION_NEEDED, $token->getAuthenticationStatus());
        self::assertSame('the-assertion', $token->getCredentials()['assertion']);
    }

    #[Test]
    public function tokenIgnoresAPlainAssertionArgument(): void
    {
        // the 2nd-factor WebAuthn endpoint posts a plain `assertion` - it must not trigger a passwordless login
        $token = new WebAuthnPasswordlessToken();
        $token->updateCredentials($this->actionRequest('POST', ['assertion' => 'the-assertion'], []));

        self::assertSame(TokenInterface::NO_CREDENTIALS_GIVEN, $token->getAuthenticationStatus());
    }

    #[Test]
    public function tokenIgnoresGetRequests(): void
    {
        $token = new WebAuthnPasswordlessToken();
        $token->updateCredentials($this->actionRequest('GET', [], $this->namespacedAssertion('the-assertion')));

        self::assertSame(TokenInterface::NO_CREDENTIALS_GIVEN, $token->getAuthenticationStatus());
    }

    private function tokenWithAssertion(string $assertion): WebAuthnPasswordlessToken
    {
        $token = new WebAuthnPasswordlessToken();
        $token->updateCredentials($this->actionRequest('POST', [], $this->namespacedAssertion($assertion)));
        return $token;
    }

    private function namespacedAssertion(string $assertion): array
    {
        return ['__authentication' => ['Sandstorm' => ['BackendUserManagement' => ['Passwordless' => ['assertion' => $assertion]]]]];
    }

    private function actionRequest(string $method, array $arguments, array $internalArguments): ActionRequest
    {
        $actionRequest = $this->createMock(ActionRequest::class);
        $actionRequest->method('getHttpRequest')->willReturn(new ServerRequest($method, 'https://neos.example/'));
        $actionRequest->method('getArguments')->willReturn($arguments);
        $actionRequest->method('getInternalArguments')->willReturn($internalArguments);
        return $actionRequest;
    }
}
