<?php

namespace Sandstorm\BackendUserManagement\SecondFactor\Service;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\Security\Account;
use Sandstorm\BackendUserManagement\SecondFactor\Domain\Repository\SecondFactorRepository;
use Sandstorm\BackendUserManagement\UserManagement\Application\Throttle;

class SecondFactorService
{
    /**
     * @Flow\InjectConfiguration(path="secondFactor.enforceForAll")
     * @var bool
     */
    protected $enforceForAll;

    /**
     * @Flow\InjectConfiguration(path="secondFactor.enforceForAuthenticationProviders")
     * @var array
     */
    protected $enforceForAuthenticationProviders;

    /**
     * @Flow\InjectConfiguration(path="secondFactor.enforceForRoles")
     * @var array
     */
    protected $enforceForRoles;

    /**
     * @Flow\Inject
     * @var SecondFactorRepository
     */
    protected $secondFactorRepository;

    /**
     * @Flow\Inject
     * @var Throttle
     */
    protected $throttle;

    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * @Flow\InjectConfiguration(path="secondFactor.throttle.perAccount")
     * @var array{limit: int, window: string}
     */
    protected $throttlePerAccount;

    /**
     * Consume one second-factor check for the account. Returns false if the account exceeded its limit - the code must
     * then not be checked at all. Keyed by the account's persistence identifier, so a new session (cancel + log in
     * again) doesn't get a fresh limit.
     */
    public function attemptSecondFactorCheck(Account $account): bool
    {
        return $this->throttle->attempt(
            'secondFactor:account:' . $this->persistenceManager->getIdentifierByObject($account),
            (int)$this->throttlePerAccount['limit'],
            (string)$this->throttlePerAccount['window']
        );
    }

    /**
     * Check if the second factor is enforced for the given account.
     *
     * The second factor is enforced if:
     *   - it is enforced for all accounts or
     *   - it is enforced for a role of the account or
     *   - it is enforced for the authentication provider of the account
     */
    public function isSecondFactorEnforcedForAccount(Account $account): bool
    {
        $isEnforcedForAll = $this->enforceForAll;
        $isEnforcedForRoles = count(array_intersect(
            array_map(fn($item) => $item->getIdentifier(), $account->getRoles()),
            $this->enforceForRoles
        ));
        $isEnforcedForAuthenticationProviders = in_array(
            $account->getAuthenticationProviderName(),
            $this->enforceForAuthenticationProviders
        );

        return $isEnforcedForAll || $isEnforcedForRoles || $isEnforcedForAuthenticationProviders;
    }

    /**
     * Check if the account has setup at least 1 second factor.
     */
    public function isSecondFactorEnabledForAccount(Account $account): bool
    {
        return $this->secondFactorRepository->countByAccount($account) > 0;
    }

    /**
     * Check if the account can delete 1 second factor.
     *
     * Second factor can only be deleted if it is not enforced for the account or if the account has multiple factors.
     */
    public function canOneSecondFactorBeDeletedForAccount(Account $account): bool
    {
        $isEnforcedForAccount = $this->isSecondFactorEnforcedForAccount($account);
        $hasMultipleFactors = $this->secondFactorRepository->countByAccount($account) > 1;

        return !$isEnforcedForAccount || $hasMultipleFactors;
    }
}
