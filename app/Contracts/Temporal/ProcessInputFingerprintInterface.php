<?php

declare(strict_types=1);

namespace App\Contracts\Temporal;

interface ProcessInputFingerprintInterface
{
    public function processFingerprint(): string;
}
