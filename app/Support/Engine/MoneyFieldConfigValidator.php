<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Models\FieldDefinition;
use Illuminate\Support\Facades\Validator;

class MoneyFieldConfigValidator
{
    public function validate(FieldDefinition $field): void
    {
        Validator::make($field->config ?? [], [
            'currency' => ['sometimes', 'string', 'regex:/^[A-Z]{3}$/', 'prohibits:currency_field'],
            'currency_field' => ['sometimes', 'string', 'max:255', 'regex:/^[a-z][a-z0-9_]*$/', 'prohibits:currency'],
        ])->validate();
    }
}
