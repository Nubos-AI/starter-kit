<?php

declare(strict_types=1);

namespace App\Exceptions\Engine;

use RuntimeException;

class RecordNumberOverflowException extends RuntimeException
{
    public static function for(string $format, int $sequence): self
    {
        return new self(
            sprintf(__('i18n.backend.exceptions.engine.record_number_overflow_exception.the_counter_d_no_longer_fits_the_number_format'), $sequence, $format),
        );
    }
}
