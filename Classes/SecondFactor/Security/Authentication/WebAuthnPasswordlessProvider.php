<?php

namespace Sandstorm\BackendUserManagement\SecondFactor\Security\Authentication;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Http\HttpRequestHandlerInterface;
use Neos\Flow\Http\ServerRequestAttributes;
use Neos\Flow\Security\Authentication\Provider\AbstractProvider;
use Neos\Flow\Security\Authentication\TokenInterface;
use Neos\Flow\Security\Exception\UnsupportedAuthenticationTokenException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Sandstorm\BackendUserManagement\SecondFactor\Domain\AuthenticationStatus;
use Sandstorm\BackendUserManagement\SecondFactor\Security\Token\WebAuthnPasswordlessToken;
use Sandstorm\BackendUserManagement\SecondFactor\Service\SecondFactorSessionStorageService;
use Sandstorm\BackendUserManagement\SecondFactor\Service\WebAuthnService;

/**
 * Authentication provider for usernameless passwordless passkey login.
 *
 * Verifies the WebAuthn assertion carried by the {@see WebAuthnPasswordlessToken} against the challenge
 * stored in the session by {@see \Sandstorm\BackendUserManagement\SecondFactor\Controller\PasswordlessLoginController::optionsAction()}.
 * It runs inside Flow's AuthenticationProviderManager, which on success renews the session id and tags the
 * session with the account (so Neos can destroy it on deactivate / delete / password change).
 *
 * With the `oneToken` authentication strategy an authenticated parallel token is enough for the request to
 * count as authenticated; the resolved account is a regular `Neos.Neos:Backend` account, so roles and the
 * current Neos user resolve normally.
 */
class WebAuthnPasswordlessProvider extends AbstractProvider
{
    private const LOGGING_PREFIX = 'Sandstorm/BackendUserManagement: ';

    /**
     * @Flow\Inject
     * @var WebAuthnService
     */
    protected $webAuthnService;

    /**
     * @Flow\Inject
     * @var SecondFactorSessionStorageService
     */
    protected $secondFactorSessionStorageService;

    /**
     * @Flow\Inject
     * @var Bootstrap
     */
    protected $bootstrap;

    /**
     * @Flow\Inject(name="Neos.Flow:SecurityLogger")
     * @var LoggerInterface
     */
    protected $securityLogger;

    /**
     * @Flow\InjectConfiguration(path="secondFactor.webAuthn.passwordlessLoginEnabled", package="Sandstorm.BackendUserManagement")
     * @var bool
     */
    protected $passwordlessLoginEnabled = false;

    /**
     * @return array<string>
     */
    public function getTokenClassNames()
    {
        return [WebAuthnPasswordlessToken::class];
    }

    /**
     * Only called by the AuthenticationProviderManager for tokens in state AUTHENTICATION_NEEDED, i.e. when the
     * request carried an assertion (see WebAuthnPasswordlessToken::updateCredentials()).
     */
    public function authenticate(TokenInterface $authenticationToken)
    {
        if (!$authenticationToken instanceof WebAuthnPasswordlessToken) {
            throw new UnsupportedAuthenticationTokenException(
                sprintf('This provider cannot authenticate the given token "%s".', get_class($authenticationToken)),
                1793795726
            );
        }

        $httpRequest = $this->currentHttpRequest();

        // The token is active on every request - so this is checked here, not only in the controller.
        if (!$this->passwordlessLoginEnabled) {
            $this->rejectLogin($authenticationToken, 'passwordless login is disabled', $httpRequest);
            return;
        }

        $assertion = $authenticationToken->getCredentials()['assertion'] ?? null;
        $serializedOptions = $this->secondFactorSessionStorageService->getValue(
            SecondFactorSessionStorageService::SESSION_OBJECT_WEBAUTHN_PASSWORDLESS_OPTIONS
        );
        if (!is_string($assertion) || !is_string($serializedOptions) || $httpRequest === null) {
            $this->rejectLogin($authenticationToken, 'no passwordless login in progress', $httpRequest);
            return;
        }
        // Single use: the challenge is gone after one verification attempt, whatever its outcome.
        $this->secondFactorSessionStorageService->removeValue(
            SecondFactorSessionStorageService::SESSION_OBJECT_WEBAUTHN_PASSWORDLESS_OPTIONS
        );

        try {
            $account = $this->webAuthnService->verifyPasswordlessAssertion(
                $assertion,
                $this->webAuthnService->requestOptionsFromJson($serializedOptions),
                $httpRequest
            );
        } catch (\Throwable $exception) {
            $this->rejectLogin(
                $authenticationToken,
                sprintf('%s (%s)', $exception->getMessage(), $exception->getCode()),
                $httpRequest
            );
            return;
        }

        $authenticationToken->setAccount($account);
        $authenticationToken->setAuthenticationStatus(TokenInterface::AUTHENTICATION_SUCCESSFUL);

        // A user-verified passkey is itself multi-factor, so it also satisfies the 2FA gate.
        $this->secondFactorSessionStorageService->initializeTwoFactorSessionObject();
        $this->secondFactorSessionStorageService->setAuthenticationStatus(AuthenticationStatus::AUTHENTICATED);
    }

    private function rejectLogin(WebAuthnPasswordlessToken $token, string $reason, ?ServerRequestInterface $httpRequest): void
    {
        $token->setAuthenticationStatus(TokenInterface::WRONG_CREDENTIALS);
        $clientIp = $httpRequest?->getAttribute(ServerRequestAttributes::CLIENT_IP);
        $this->securityLogger->warning(
            self::LOGGING_PREFIX . 'Passwordless passkey login failed: ' . $reason,
            ['clientIp' => is_string($clientIp) ? $clientIp : 'unknown']
        );
    }

    private function currentHttpRequest(): ?ServerRequestInterface
    {
        $requestHandler = $this->bootstrap->getActiveRequestHandler();
        return $requestHandler instanceof HttpRequestHandlerInterface ? $requestHandler->getHttpRequest() : null;
    }
}
