<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Enums\Mail\SmtpFailureKind;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Throwable;

class SmtpFailureClassifier
{
    /**
     * @var list<string>
     */
    private array $connectionFailures = [
        'Connection could not be established with host',
        'Unable to connect with STARTTLS',
    ];

    public function classify(Throwable $failure): ?SmtpFailureKind
    {
        $transportFailure = $this->transportFailureIn($failure);

        if (!$transportFailure instanceof TransportExceptionInterface) {
            return null;
        }

        if (Str::startsWith($transportFailure->getMessage(), $this->connectionFailures)) {
            return SmtpFailureKind::NotConnected;
        }

        if ($transportFailure instanceof UnexpectedResponseException) {
            return SmtpFailureKind::Refused;
        }

        return SmtpFailureKind::Interrupted;
    }

    public function isSafeToRetry(Throwable $failure): bool
    {
        return $this->classify($failure) === SmtpFailureKind::NotConnected;
    }

    private function transportFailureIn(Throwable $failure): ?TransportExceptionInterface
    {
        for ($cause = $failure; $cause instanceof Throwable; $cause = $cause->getPrevious()) {
            if ($cause instanceof TransportExceptionInterface) {
                return $cause;
            }
        }

        return null;
    }
}
