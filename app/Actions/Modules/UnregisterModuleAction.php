<?php

declare(strict_types=1);

namespace App\Actions\Modules;

use App\Models\Module;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class UnregisterModuleAction
{
    /**
     * @throws ValidationException
     */
    public function execute(string $name): void
    {
        Validator::make(['name' => $name], ['name' => ['required', 'string']])->validate();

        Module::query()->where('name', $name)->delete();
    }
}
