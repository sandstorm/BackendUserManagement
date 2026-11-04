<?php

namespace Sandstorm\BackendUserManagement\SecondFactor\Security\Token;

use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Security\Authentication\Token\AbstractToken;
use Neos\Utility\ObjectAccess;

/**
 * Authentication token for usernameless, passwordless passkey login.
 *
 * Works like Flow's UsernamePassword token: it picks the WebAuthn assertion up from a namespaced
 * internal POST argument and marks itself AUTHENTICATION_NEEDED, so the regular
 * AuthenticationProviderManager runs the
 * {@see \Sandstorm\BackendUserManagement\SecondFactor\Security\Authentication\WebAuthnPasswordlessProvider}
 * - including session id renewal and session tagging on success.
 *
 * The argument is namespaced on purpose: the token has no request pattern, so it is active on every
 * request. A plain `assertion` argument would also match the 2nd-factor WebAuthn endpoint.
 */
class WebAuthnPasswordlessToken extends AbstractToken
{
    public const ASSERTION_FIELD = '__authentication.Sandstorm.BackendUserManagement.Passwordless.assertion';

    public function updateCredentials(ActionRequest $actionRequest)
    {
        if ($actionRequest->getHttpRequest()->getMethod() !== 'POST') {
            return;
        }
        $allArguments = array_merge($actionRequest->getArguments(), $actionRequest->getInternalArguments());
        $assertion = ObjectAccess::getPropertyPath($allArguments, self::ASSERTION_FIELD);
        if (is_string($assertion) && $assertion !== '') {
            $this->credentials['assertion'] = $assertion;
            $this->setAuthenticationStatus(self::AUTHENTICATION_NEEDED);
        }
    }
}
