<?php

namespace Sandstorm\BackendUserManagement\Tests\Unit\UserManagement;

use Neos\Flow\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Sandstorm\BackendUserManagement\UserManagement\Application\UserMailer;
use Sandstorm\BackendUserManagement\UserManagement\Application\UserManagementException;

/**
 * Links in mails must only ever use a configured base URI - never the (attacker-controlled) Host of the request.
 */
class UserMailerBaseUriTest extends UnitTestCase
{
    #[Test]
    public function linksUseTheMailBaseUri(): void
    {
        $mailer = $this->mailer('https://cms.example.com/', 'https://flow.example.com/');

        self::assertSame('https://cms.example.com/neos/password/set/abc', $this->buildUri($mailer, '/neos/password/set/abc'));
    }

    #[Test]
    public function linksFallBackToTheFlowBaseUri(): void
    {
        $mailer = $this->mailer(null, 'https://flow.example.com');

        self::assertSame('https://flow.example.com/neos/login', $this->buildUri($mailer, '/neos/login'));
    }

    /**
     * @return array<string, array{mixed, mixed}>
     */
    public static function unconfiguredBaseUris(): array
    {
        return [
            'not set' => [null, null],
            // an unset %env:...% placeholder
            'unset env variable' => [false, false],
            'empty' => ['', '  '],
        ];
    }

    #[Test]
    #[DataProvider('unconfiguredBaseUris')]
    public function withoutAConfiguredBaseUriNoLinkIsBuilt(mixed $mailBaseUri, mixed $flowBaseUri): void
    {
        $mailer = $this->mailer($mailBaseUri, $flowBaseUri);

        self::assertFalse($mailer->isBaseUriConfigured());
        $this->expectException(UserManagementException::class);
        $this->expectExceptionCode(1793800007);
        $this->buildUri($mailer, '/neos/login');
    }

    #[Test]
    public function theTestMailFailsWithoutABaseUriLikeTheRealMails(): void
    {
        // it contains a login link - so it does not report a working configuration the real mails don't have
        $mailer = $this->mailer(null, null);

        $this->expectException(UserManagementException::class);
        $this->expectExceptionMessage('error.baseUriMissing');
        $mailer->sendTestMail('admin@example.com', null);
    }

    private function mailer(mixed $mailBaseUri, mixed $flowBaseUri): UserMailer
    {
        $mailer = new UserMailer();
        $this->inject($mailer, 'mailBaseUri', $mailBaseUri);
        $this->inject($mailer, 'flowBaseUri', $flowBaseUri);
        return $mailer;
    }

    private function buildUri(UserMailer $mailer, string $path): string
    {
        return (fn (string $path) => $this->buildUri($path))->call($mailer, $path);
    }
}
