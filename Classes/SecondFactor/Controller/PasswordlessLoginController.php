<?php

namespace Sandstorm\BackendUserManagement\SecondFactor\Controller;

/*
 * This file is part of the Sandstorm.BackendUserManagement package.
 */

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\Mvc\View\JsonView;
use Neos\Flow\Security\Authentication\AuthenticationManagerInterface;
use Neos\Flow\Security\Context as SecurityContext;
use Neos\Flow\Security\Exception\AuthenticationRequiredException;
use Sandstorm\BackendUserManagement\SecondFactor\Security\Token\WebAuthnPasswordlessToken;
use Sandstorm\BackendUserManagement\SecondFactor\Service\SecondFactorSessionStorageService;
use Sandstorm\BackendUserManagement\SecondFactor\Service\WebAuthnService;

/**
 * XHR endpoints for usernameless, passwordless passkey login from the Neos login screen.
 *
 * Both actions are reachable while logged out (this controller is intentionally NOT behind
 * the Neos.Neos:Backend request pattern). They are hard-gated by the
 * `secondFactor.webAuthn.passwordlessLoginEnabled` setting (off by default) so that, while disabled, no
 * one can authenticate through this path even if the UI button were somehow present. The
 * {@see \Sandstorm\BackendUserManagement\SecondFactor\Security\Authentication\WebAuthnPasswordlessProvider}
 * checks the setting again, as its token is active on every request.
 */
class PasswordlessLoginController extends ActionController
{
    protected $supportedMediaTypes = ['application/json'];

    protected $defaultViewObjectName = JsonView::class;

    /**
     * @Flow\Inject
     * @var SecurityContext
     */
    protected $securityContext;

    /**
     * @Flow\Inject
     * @var AuthenticationManagerInterface
     */
    protected $authenticationManager;

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
     * @Flow\InjectConfiguration(path="secondFactor.webAuthn.passwordlessLoginEnabled")
     * @var bool
     */
    protected $passwordlessLoginEnabled = false;

    /**
     * Ceremony step 1: return the request options for navigator.credentials.get().
     * Usernameless — no allowCredentials, so the browser offers any discoverable passkey.
     *
     * @Flow\SkipCsrfProtection
     */
    public function optionsAction(): string
    {
        if (!$this->passwordlessLoginEnabled) {
            return $this->jsonError('Passwordless login is disabled', 403);
        }
        // The visitor is not authenticated yet, so no session exists — start one to hold the
        // challenge across the options -> verify round trip.
        $this->secondFactorSessionStorageService->startSessionIfNotStarted();
        $hostname = $this->request->getHttpRequest()->getUri()->getHost();
        $options = $this->webAuthnService->createPasswordlessAuthenticationOptions($hostname);
        $optionsJson = $this->webAuthnService->optionsToJson($options);
        $this->secondFactorSessionStorageService->putValue(
            SecondFactorSessionStorageService::SESSION_OBJECT_WEBAUTHN_PASSWORDLESS_OPTIONS,
            $optionsJson
        );
        $this->response->setContentType('application/json');
        return $optionsJson;
    }

    /**
     * Ceremony step 2: the assertion arrives as the internal argument
     * {@see WebAuthnPasswordlessToken::ASSERTION_FIELD}, which puts the token into AUTHENTICATION_NEEDED.
     * Flow's AuthenticationManager then runs the WebAuthnPasswordlessProvider (verification, session id
     * renewal, session tagging). Here we only report the outcome and the URI to redirect to.
     *
     * @Flow\SkipCsrfProtection
     */
    public function verifyAction(): string
    {
        if (!$this->passwordlessLoginEnabled) {
            return $this->jsonError('Passwordless login is disabled', 403);
        }

        try {
            // usually already done by the PolicyEnforcement interceptor - a no-op then
            $this->authenticationManager->authenticate();
        } catch (AuthenticationRequiredException) {
            // evaluated via the token below
        }

        $token = $this->securityContext->getAuthenticationTokensOfType(WebAuthnPasswordlessToken::class)[0] ?? null;
        if ($token === null || !$token->isAuthenticated()) {
            // the reason is in the security log - don't hand webauthn-lib details to the client
            return $this->jsonError('Passkey login failed', 401);
        }

        $this->response->setContentType('application/json');
        return json_encode([
            'status' => 'ok',
            'redirect' => $this->interceptedRequestOrBackendUri(),
        ], JSON_THROW_ON_ERROR);
    }

    private function jsonError(string $message, int $code): string
    {
        $this->response->setStatusCode($code);
        $this->response->setContentType('application/json');
        return json_encode(['status' => 'error', 'message' => $message], JSON_THROW_ON_ERROR);
    }

    /**
     * Resolve the post-login redirect: the originally intercepted request if there is one,
     * otherwise the Neos backend index — always through routing, never a hardcoded path.
     */
    private function interceptedRequestOrBackendUri(): string
    {
        $uriBuilder = $this->controllerContext->getUriBuilder();
        $originalRequest = $this->securityContext->getInterceptedRequest();
        if ($originalRequest !== null) {
            return (string)$uriBuilder->uriFor(
                $originalRequest->getControllerActionName(),
                $originalRequest->getArguments(),
                $originalRequest->getControllerName(),
                $originalRequest->getControllerPackageKey()
            );
        }
        return (string)$uriBuilder->uriFor('index', [], 'Backend\Backend', 'Neos.Neos');
    }
}
