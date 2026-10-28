<?php

declare(strict_types=1);

namespace Sandstorm\BackendUserManagement\UserManagement\Application;

use Flowpack\JobQueue\Common\Job\JobInterface;
use Flowpack\JobQueue\Common\Queue\Message;
use Flowpack\JobQueue\Common\Queue\QueueInterface;
use Neos\Flow\Annotations as Flow;
use Neos\SymfonyMailer\Exception\InvalidMailerConfigurationException;
use Neos\SymfonyMailer\Service\MailerService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Sends an already rendered mail from the {@see UserMailer::QUEUE_NAME} queue - so the SMTP round trip is not part
 * of the request.
 */
class SendMailJob implements JobInterface
{
    #[Flow\Inject]
    protected MailerService $mailerService;

    #[Flow\Inject(name: 'Neos.Flow:SecurityLogger', lazy: false)]
    protected LoggerInterface $securityLogger;

    public function __construct(
        /**
         * MUST NOT be private: the job is serialized into the queue, and Flow's proxy __sleep() only sees properties of
         * the proxy class - a private property of this (original) class would be dropped silently.
         */
        protected Email $email,
    ) {
    }

    /**
     * @throws Throwable
     */
    public function execute(QueueInterface $queue, Message $message): bool
    {
        try {
            $this->mailerService->getMailer()->send($this->email);
        } catch (Throwable $exception) {
            // the async FakeQueue discards the output - without this, a failing mail would leave no trace
            $this->securityLogger->error(
                'Sandstorm/BackendUserManagement: could not send queued mail: ' . $exception->getMessage(),
            );
            // a real queue releases and retries the job
            throw $exception;
        }
        return true;
    }

    public function getLabel(): string
    {
        return 'Sandstorm.BackendUserManagement: send mail "' . $this->email->getSubject() . '"';
    }
}
