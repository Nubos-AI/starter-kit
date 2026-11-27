<?php

declare(strict_types=1);

namespace App\Enums\Mail;

enum SmtpFailureKind: string
{
    case NotConnected = 'not_connected';

    case Refused = 'refused';

    case Interrupted = 'interrupted';
}
