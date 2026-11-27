<?php

declare(strict_types=1);

namespace App\Actions\Mail;

use App\DTOs\Mail\SystemMailData;
use App\DTOs\Mail\SystemMailEnvelope;
use App\Exceptions\Mail\SystemMailEnvelopeRejectedException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Traits\Localizable;
use UnexpectedValueException;

class DeliverSystemMailAction
{
    use Localizable;

    public function __construct(
        private readonly Encrypter $encrypter,
        private readonly MailChannel $mailChannel,
        private readonly Mailer $mailer,
        private readonly Repository $config,
    ) {}

    /**
     * @throws SystemMailEnvelopeRejectedException
     * @throws UnexpectedValueException
     */
    public function execute(SystemMailData $mail): void
    {
        $serialized = $mail->unsealedEnvelope($this->encrypter);
        $envelope = unserialize($serialized, ['allowed_classes' => $this->allowedClassesIn($mail, $serialized)]);

        if (!$envelope instanceof SystemMailEnvelope) {
            throw new UnexpectedValueException("The sealed system mail \"{$mail->deliveryKey}\" holds no mail envelope.");
        }

        if ($envelope->mailable instanceof Mailable) {
            $envelope->mailable->locale($envelope->locale)->send($this->mailer);

            return;
        }

        $notification = $envelope->notification;

        if (!$notification instanceof Notification) {
            throw new UnexpectedValueException("The sealed system mail \"{$mail->deliveryKey}\" holds neither a notification nor a mailable.");
        }

        $this->withLocale($envelope->locale, fn () => $this->mailChannel->send($envelope->notifiable, $notification));
    }

    /**
     * @return list<string>
     *
     * @throws SystemMailEnvelopeRejectedException
     */
    private function allowedClassesIn(SystemMailData $mail, string $serialized): array
    {
        preg_match_all('/(?:[OC]:\d+:"([^"]+)"|E:\d+:"([^":]+):)/', $serialized, $matches);

        $classes = array_values(array_unique(array_filter([...$matches[1], ...$matches[2]], static fn (string $class): bool => $class !== '')));

        foreach ($classes as $class) {
            if (!$this->isAllowedClass($class)) {
                throw SystemMailEnvelopeRejectedException::forClass($mail->deliveryKey, $class);
            }
        }

        return $classes;
    }

    private function isAllowedClass(string $class): bool
    {
        if ($class === SystemMailEnvelope::class || is_a($class, Notification::class, true) || is_a($class, Mailable::class, true)) {
            return true;
        }

        /** @var list<string> $allowed */
        $allowed = (array) $this->config->get('mail.system_delivery.envelope_classes');

        foreach ($allowed as $entry) {
            if ($class === $entry || (str_ends_with($entry, '\\') && str_starts_with($class, $entry))) {
                return true;
            }
        }

        return false;
    }
}
