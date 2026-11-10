<?php

declare(strict_types=1);

namespace App\Exceptions\Engine;

use RuntimeException;

class IncompleteRollupChildSetException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(__('i18n.backend.exceptions.engine.incomplete_rollup_child_set_exception.the_child_set_of_the_roll_up_could_not'));
    }
}
