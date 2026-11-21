<?php

declare(strict_types=1);

namespace App\Exceptions\Engine;

use Illuminate\Validation\ValidationException;

class HierarchyCycleException extends ValidationException {}
