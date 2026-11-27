<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Contracts\Mail\DeliverSystemMailWorkflowInterface;
use App\Contracts\Mail\SystemMailDispatcherInterface;
use App\Contracts\Temporal\ProcessStarterInterface;
use App\DTOs\Mail\SystemMailData;
use App\DTOs\Mail\SystemMailEnvelope;
use App\DTOs\Temporal\ProcessStartData;
use App\Exceptions\Mail\SystemMailTooLargeException;
use App\Support\Temporal\ProcessId;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Temporal\Common\IdReusePolicy;

class TemporalSystemMailDispatcher implements SystemMailDispatcherInterface
{
    private string $process = 'system-mail';

    public function __construct(
        private readonly ProcessStarterInterface $processes,
        private readonly Encrypter $encrypter,
        private readonly Repository $config,
        private readonly Translator $translator,
    ) {}

    public function deliverNotification(mixed $notifiable, Notification $notification): void
    {
        $deliveryKey = is_string($notification->id) && $notification->id !== '' ? $notification->id : (string) Str::ulid();

        $this->deliver(
            new SystemMailEnvelope(
                locale: $this->localeOf($notification->locale),
                notifiable: $notifiable,
                notification: $notification,
            ),
            $notification::class,
            $deliveryKey,
            $this->tenantOf($notifiable),
        );
    }

    public function deliverMailable(Mailable $mailable, string $deliveryKey, ?string $tenantId = null): void
    {
        $this->deliver(
            new SystemMailEnvelope(
                locale: $this->localeOf($mailable->locale),
                mailable: $mailable,
            ),
            $mailable::class,
            $deliveryKey,
            $tenantId,
        );
    }

    private function deliver(SystemMailEnvelope $envelope, string $mailClass, string $deliveryKey, ?string $tenantId): void
    {
        $mail = SystemMailData::sealed($this->encrypter, $tenantId, $deliveryKey, $mailClass, serialize($envelope));
        $bytes = strlen($mail->sealedEnvelope);
        $limit = (int) $this->config->get('mail.system_delivery.max_envelope_bytes');

        if ($bytes > $limit) {
            Log::error('System mail exceeds the workflow payload limit and was not handed to Temporal.', ['mail_class' => $mailClass, 'delivery_key' => $deliveryKey, 'bytes' => $bytes, 'limit' => $limit]);

            throw SystemMailTooLargeException::make($mailClass, $bytes, $limit);
        }

        $workflowId = $tenantId === null
            ? ProcessId::global($this->process, $deliveryKey)
            : ProcessId::forTenant($this->process, $tenantId, $deliveryKey);

        $isStarted = $this->processes->start(new ProcessStartData(
            workflow: DeliverSystemMailWorkflowInterface::class,
            workflowId: $workflowId,
            arguments: [$mail],
            reusePolicy: IdReusePolicy::AllowDuplicateFailedOnly,
        ));

        Log::info('System mail handed to Temporal for delivery.', ['mail_class' => $mailClass, 'workflow_id' => $workflowId, 'is_started' => $isStarted]);
    }

    private function localeOf(?string $locale): string
    {
        return is_string($locale) && $locale !== '' ? $locale : $this->translator->getLocale();
    }

    private function tenantOf(mixed $notifiable): ?string
    {
        if (!$notifiable instanceof Model) {
            return null;
        }

        $tenantId = $notifiable->getAttribute('tenant_id');

        return is_string($tenantId) && $tenantId !== '' ? $tenantId : null;
    }
}
