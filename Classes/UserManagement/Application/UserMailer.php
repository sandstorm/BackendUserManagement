<?php

declare(strict_types=1);

namespace Sandstorm\BackendUserManagement\UserManagement\Application;

use DateTimeImmutable;
use Flowpack\JobQueue\Common\Job\JobManager;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\I18n\Locale;
use Neos\Flow\I18n\Service as I18nService;
use Neos\Fusion\View\FusionView;
use Neos\Neos\Domain\Model\User;
use Neos\SymfonyMailer\Service\MailerService;
use Psr\Http\Message\ResponseInterface;
use Sandstorm\BackendUserManagement\UserManagement\Domain\Dto\UsernameChange;
use Sandstorm\BackendUserManagement\UserManagement\Domain\UserActionType;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Sends the user management mails via neos/symfonymailer. Bodies (HTML + plain text) are rendered from
 * Fusion (Resources/Private/Fusion/UserManagement/Mail) in the recipient's backend interface language.
 *
 * Throws on any transport or configuration error - callers decide whether to show or swallow it. Mails sent with
 * $async are only rendered right away; they are sent by a {@see SendMailJob} from the {@see self::QUEUE_NAME} queue,
 * which logs transport errors.
 */
#[Flow\Scope('singleton')]
class UserMailer
{
    /**
     * Paths of the public routes, see Configuration/Routes.yaml
     */
    private const string SET_PASSWORD_PATH = '/neos/password/set/';
    private const string CONFIRM_EMAIL_PATH = '/neos/email/confirm/';
    private const string LOGIN_PATH = '/neos/login';

    /**
     * Flowpack.JobQueue.Common queue, see Configuration/Settings.UserManagement.yaml
     */
    public const string QUEUE_NAME = 'Sandstorm.BackendUserManagement:UserManagementMail';

    #[Flow\Inject]
    protected JobManager $jobManager;

    #[Flow\Inject]
    protected MailerService $mailerService;

    /**
     * Base URI of the links in mails. Falls back to Neos.Flow.http.baseUri. Never taken from the current request:
     * its Host / X-Forwarded-Host header is attacker-controlled - a forged one on "forgot password" would send the
     * victim a valid token pointing to the attacker's domain.
     * Typed mixed: an unset %env:...% placeholder yields false, not null.
     */
    #[Flow\InjectConfiguration(path: 'userManagement.mail.baseUri')]
    protected mixed $mailBaseUri = null;

    #[Flow\InjectConfiguration(path: 'http.baseUri', package: 'Neos.Flow')]
    protected mixed $flowBaseUri = null;

    #[Flow\Inject]
    protected I18nService $i18nService;

    /** @var array{from?: array{address?: ?string, name?: ?string}} */
    #[Flow\InjectConfiguration(path: 'userManagement.mail')]
    protected array $mailSettings = [];

    #[Flow\InjectConfiguration(path: 'userInterface.defaultLanguage', package: 'Neos.Neos')]
    protected ?string $defaultLanguage = null;

    /**
     * One mail with one link per account (several accounts may share the same email address).
     *
     * @param list<array{username: string, token: string}> $links
     * @param bool $async send via the queue - so the response time does not tell whether an account exists
     */
    public function sendPasswordLinks(
        string $recipient,
        ?User $user,
        UserActionType $action,
        array $links,
        DateTimeImmutable $expiresAt,
        bool $async = false,
    ): void {
        $this->send(
            $recipient,
            $user,
            'passwordLinks',
            [
                'action' => $action->value,
                'links' => array_map(fn(array $link)
                    => [
                    'username' => $link['username'],
                    'uri' => $this->buildUri(self::SET_PASSWORD_PATH . $link['token']),
                ], $links),
                'expiresAt' => $expiresAt,
                'name' => $user?->getName()->getFullName() ?? '',
            ],
            $async,
        );
    }

    public function sendEmailVerification(
        string $recipient,
        User $user,
        string $token,
        DateTimeImmutable $expiresAt,
    ): void {
        $this->send($recipient, $user, 'emailVerification', [
            'uri' => $this->buildUri(self::CONFIRM_EMAIL_PATH . $token),
            'expiresAt' => $expiresAt,
            'name' => $user->getName()->getFullName(),
        ]);
    }

    /**
     * @param list<UsernameChange> $usernameChanges
     */
    public function sendUsernameChanged(string $recipient, User $user, array $usernameChanges, bool $emailConfirmed): void
    {
        $this->send($recipient, $user, 'usernameChanged', [
            'emailConfirmed' => $emailConfirmed,
            'oldUsernames' => implode(
                ', ',
                array_map(fn(UsernameChange $change) => $change->oldUsername, $usernameChanges),
            ),
            'newUsername' => $usernameChanges[0]->newUsername,
            'name' => $user->getName()->getFullName(),
            'loginUri' => $this->buildUri(self::LOGIN_PATH),
        ]);
    }

    public function sendTestMail(string $recipient, ?User $user): void
    {
        $this->send($recipient, $user, 'testMail', [
            'name' => $user?->getName()->getFullName() ?? '',
            // a link like in the real mails - so the test mail also fails without a base URI, and its host can be checked
            'loginUri' => $this->buildUri(self::LOGIN_PATH),
        ]);
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function send(
        string $recipient,
        ?User $user,
        string $mailName,
        array $variables,
        bool $async = false,
    ): void {
        $fromAddress = $this->mailSettings['from']['address'] ?? null;
        if ($fromAddress === null || $fromAddress === '') {
            throw new \RuntimeException(
                'Sandstorm.BackendUserManagement.userManagement.mail.from.address is not configured.', 1760000001,
            );
        }

        $locale = $user?->getPreferences()->getInterfaceLanguage() ?: $this->defaultLanguage ?: 'en';
        $subject = $this->render($mailName . '.subject', $variables, $locale);
        $html = $this->render($mailName . '.html', $variables, $locale);
        $text = $this->render($mailName . '.text', $variables, $locale);

        $email = (new Email())
            ->from(new Address($fromAddress, $this->mailSettings['from']['name'] ?? ''))
            ->to($recipient)
            ->subject(trim($subject))
            ->html($html)
            ->text($text);

        if ($async) {
            $this->jobManager->queue(self::QUEUE_NAME, new SendMailJob($email));
            return;
        }
        $this->mailerService->getMailer()->send($email);
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function render(string $fusionPath, array $variables, string $localeIdentifier): string
    {
        $i18nConfiguration = $this->i18nService->getConfiguration();
        $previousLocale = $i18nConfiguration->getCurrentLocale();
        try {
            $i18nConfiguration->setCurrentLocale(new Locale($localeIdentifier));

            $view = new FusionView();
            // explicit: without it, Neos 8 derives the package key from the controller context - which is null
            // when rendering outside of a controller (CLI commands)
            $view->setPackageKey('Sandstorm.BackendUserManagement');
            $view->setFusionPathPatterns(['resource://Sandstorm.BackendUserManagement/Private/Fusion']);
            $view->setFusionPath(
                'Sandstorm/BackendUserManagement/UserManagement/Mail/' . str_replace('.', '/', $fusionPath),
            );
            $view->assignMultiple($variables);
            $output = $view->render();
            return $output instanceof ResponseInterface ? (string) $output->getBody() : (string) $output;
        } finally {
            $i18nConfiguration->setCurrentLocale($previousLocale);
        }
    }

    public function isBaseUriConfigured(): bool
    {
        return $this->configuredBaseUri() !== null;
    }

    /**
     * Callers check this BEFORE issuing tokens - otherwise a valid token would be stored for a mail that is never sent.
     *
     * @throws UserManagementException if no base URI is configured
     */
    public function requireBaseUri(): string
    {
        $baseUri = $this->configuredBaseUri();
        if ($baseUri === null) {
            throw new UserManagementException('error.baseUriMissing', 1793800007);
        }
        return $baseUri;
    }

    /**
     * @throws UserManagementException if no base URI is configured
     */
    private function buildUri(string $path): string
    {
        return rtrim($this->requireBaseUri(), '/') . $path;
    }

    private function configuredBaseUri(): ?string
    {
        foreach ([$this->mailBaseUri, $this->flowBaseUri] as $baseUri) {
            if (is_string($baseUri) && trim($baseUri) !== '') {
                return trim($baseUri);
            }
        }
        return null;
    }
}
