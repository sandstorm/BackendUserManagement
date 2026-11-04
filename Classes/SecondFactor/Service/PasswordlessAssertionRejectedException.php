<?php

namespace Sandstorm\BackendUserManagement\SecondFactor\Service;

use Neos\Flow\Security\Account;

/**
 * The passkey was known and belongs to an active backend account, but its assertion did not verify
 * (bad signature, wrong origin, replayed counter, missing user verification, ...).
 *
 * Carries the account, so the failed attempt can be counted on it - like Flow's password provider does.
 */
final class PasswordlessAssertionRejectedException extends \RuntimeException
{
    public function __construct(
        public readonly Account $account,
        \Throwable $previous
    ) {
        parent::__construct('Passkey assertion rejected: ' . $previous->getMessage(), 1793796650, $previous);
    }
}
