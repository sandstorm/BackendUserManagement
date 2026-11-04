<?php

namespace Sandstorm\BackendUserManagement\Tests\Unit\SecondFactor\Service;

use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\Security\Account;
use Neos\Flow\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Test;
use Sandstorm\BackendUserManagement\SecondFactor\Service\SecondFactorService;
use Sandstorm\BackendUserManagement\UserManagement\Application\Throttle;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\RateLimiter\Storage\StorageInterface;

class SecondFactorServiceTest extends UnitTestCase
{
    private SecondFactorService $service;

    private Account $jane;

    private Account $john;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SecondFactorService();

        $throttle = new class () extends Throttle {
            private InMemoryStorage $storage;

            protected function createStorage(): StorageInterface
            {
                return $this->storage ??= new InMemoryStorage();
            }

            protected function getLockFactory(): LockFactory
            {
                return new LockFactory(new InMemoryStore());
            }
        };

        $this->jane = $this->createMock(Account::class);
        $this->john = $this->createMock(Account::class);
        $persistenceManager = $this->createMock(PersistenceManagerInterface::class);
        $persistenceManager->method('getIdentifierByObject')->willReturnCallback(
            fn (Account $account) => $account === $this->jane ? 'jane-id' : 'john-id'
        );

        $this->inject($this->service, 'throttle', $throttle);
        $this->inject($this->service, 'persistenceManager', $persistenceManager);
        $this->inject($this->service, 'throttlePerAccount', ['limit' => 3, 'window' => 'PT5M']);
    }

    #[Test]
    public function secondFactorChecksAreLimitedPerAccount(): void
    {
        self::assertTrue($this->service->attemptSecondFactorCheck($this->jane));
        self::assertTrue($this->service->attemptSecondFactorCheck($this->jane));
        self::assertTrue($this->service->attemptSecondFactorCheck($this->jane));
        self::assertFalse($this->service->attemptSecondFactorCheck($this->jane));
    }

    #[Test]
    public function oneAccountsLimitDoesNotAffectAnother(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->service->attemptSecondFactorCheck($this->jane);
        }

        self::assertFalse($this->service->attemptSecondFactorCheck($this->jane));
        self::assertTrue($this->service->attemptSecondFactorCheck($this->john));
    }
}
