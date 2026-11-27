<?php

declare(strict_types=1);

namespace App\Contracts\Mail;

use App\DTOs\Mail\SystemMailData;
use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'mail.')]
interface DeliverSystemMailActivityInterface
{
    #[ActivityMethod(name: 'deliverSystemMail')]
    public function deliverSystemMail(SystemMailData $input): void;
}
