<?php

declare(strict_types=1);

namespace App\Actions\Modules;

use App\Models\Module;
use App\Support\Modules\ModuleCatalog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RegisterModuleAction
{
    public function __construct(private readonly ModuleCatalog $catalog) {}

    /**
     * @throws ValidationException
     */
    public function execute(string $name): Module
    {
        Validator::make(['name' => $name], ['name' => ['required', 'string', Rule::in($this->catalog->all())]])->validate();

        $module = Module::query()->firstOrNew(['name' => $name]);
        $module->disabled = false;
        $module->version = $this->catalog->version($name);
        $module->installed_at ??= Carbon::now();
        $module->save();

        return $module;
    }
}
