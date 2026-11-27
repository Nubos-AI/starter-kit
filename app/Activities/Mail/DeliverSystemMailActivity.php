<?php

declare(strict_types=1);

namespace App\Activities\Mail;

use App\Actions\Mail\DeliverSystemMailAction;
use App\Contracts\Mail\DeliverSystemMailActivityInterface;
use App\DTOs\Mail\SystemMailData;
use App\Enums\Mail\SmtpFailureKind;
use App\Support\Mail\SmtpFailureClassifier;
use App\Support\Temporal\ActivityFailures;
use App\Support\Tenancy\TenantBinder;
use App\Traits\Temporal\DescribesWorkflowExecution;
use Illuminate\Support\Facades\Log;
use Throwable;

class DeliverSystemMailActivity implements DeliverSystemMailActivityInterface
{
    use DescribesWorkflowExecution;

    public function __construct(
        private readonly DeliverSystemMailAction $deliverSystemMail,
        private readonly TenantBinder $tenantBinder,
        private readonly SmtpFailureClassifier $smtpFailures,
        private readonly ActivityFailures $failures,
    ) {}

    public function deliverSystemMail(SystemMailData $input): void
    {
        $context = [
            'tenant_id' => $input->tenantId,
            'delivery_key' => $input->deliveryKey,
            'mail_class' => $input->mailClass,
            ...$this->workflowExecution(),
        ];

        try {
            $this->deliver($input);
        } catch (Throwable $failure) {
            $kind = $this->smtpFailures->classify($failure);
            $isRetryable = $kind === null || $kind === SmtpFailureKind::NotConnected;

            Log::warning('System mail delivery failed.', [
                ...$context,
                'exception' => $failure::class,
                'smtp_failure' => $kind?->value,
                'is_retryable' => $isRetryable,
            ]);

            throw $this->failures->compact($failure, $isRetryable);
        }

        Log::info('System mail delivered.', $context);
    }

    private function deliver(SystemMailData $input): void
    {
        if ($input->tenantId === null) {
            $this->deliverSystemMail->execute($input);

            return;
        }

        $this->tenantBinder->run($input->tenantId, fn () => $this->deliverSystemMail->execute($input));
    }
}
