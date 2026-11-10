<?php

declare(strict_types=1);

namespace App\Exceptions\ConfigBundle;

use App\Enums\ConfigBundle\ArtifactKind;
use RuntimeException;

class UnsupportedArtifactKindException extends RuntimeException
{
    public function __construct(public readonly ArtifactKind $kind)
    {
        parent::__construct(
            "No artifact writer is registered for artifact kind \"{$kind->value}\".",
        );
    }
}
