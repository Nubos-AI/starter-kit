<?php

declare(strict_types=1);

namespace App\Contracts\CustomFields;

use App\Enums\CustomFields\FieldType;

interface FieldTypeHandler
{
    public function fieldType(): FieldType;
}
