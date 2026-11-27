<?php

declare(strict_types=1);

namespace App\Workflows\Mail;

use App\Contracts\Mail\DeliverSystemMailActivityInterface;
use App\Contracts\Mail\DeliverSystemMailWorkflowInterface;
use App\DTOs\Mail\SystemMailData;
use App\Support\Mail\SystemMailRetryOptions;
use Generator;
use Keepsuit\LaravelTemporal\Facade\Temporal;
use Temporal\Workflow;
use Throwable;

class DeliverSystemMailWorkflow implements DeliverSystemMailWorkflowInterface
{
    public function deliver(SystemMailData $input): Generator
    {
        $delivery = Temporal::newActivity()
            ->withStartToCloseTimeout(SystemMailRetryOptions::startToClose())
            ->withRetryOptions(SystemMailRetryOptions::make())
            ->build(DeliverSystemMailActivityInterface::class);

        try {
            yield $delivery->deliverSystemMail($input);
        } catch (Throwable $failure) {
            Workflow::getLogger()->error('System mail delivery gave up.', [
                ...$this->logContext($input),
                'error' => ($failure->getPrevious() ?? $failure)->getMessage(),
            ]);

            throw $failure;
        }

        Workflow::getLogger()->info('System mail delivery finished.', $this->logContext($input));
    }

    /**
     * @return array{workflow_id: string, run_id: string|null, tenant_id: string|null, delivery_key: string, mail_class: string}
     */
    private function logContext(SystemMailData $input): array
    {
        $execution = Workflow::getInfo()->execution;

        return [
            'workflow_id' => $execution->getID(),
            'run_id' => $execution->getRunID(),
            'tenant_id' => $input->tenantId,
            'delivery_key' => $input->deliveryKey,
            'mail_class' => $input->mailClass,
        ];
    }
}
