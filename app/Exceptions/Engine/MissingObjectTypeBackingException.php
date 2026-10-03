<?php

declare(strict_types=1);

namespace App\Exceptions\Engine;

use RuntimeException;

class MissingObjectTypeBackingException extends RuntimeException
{
    public function __construct(string $subject)
    {
        parent::__construct(
            "No model is bound to \"{$subject}\". Add #[BackedByObjectType] to the model "
            .'or register it in config("engine.native_backings").',
        );
    }
}
