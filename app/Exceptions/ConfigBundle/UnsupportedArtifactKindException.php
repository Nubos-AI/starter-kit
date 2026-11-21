<?php

declare(strict_types=1);

namespace App\Exceptions\ConfigBundle;

use RuntimeException;

class UnsupportedArtifactKindException extends RuntimeException
{
    public function __construct(public readonly string $kind)
    {
        parent::__construct(
            "No artifact writer is registered for artifact kind \"{$kind}\".",
        );
    }
}
