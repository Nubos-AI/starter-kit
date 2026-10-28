<?php

declare(strict_types=1);

namespace App\Actions\Modules;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

class RemoveModuleSchemaAction
{
    public function execute(string $directory): void
    {
        $migrations = glob($directory.'/*.php') ?: [];
        rsort($migrations);
        foreach ($migrations as $path) {
            $name = basename($path, '.php');
            if (!DB::table('migrations')->where('migration', $name)->exists()) {
                continue;
            }
            $migration = require $path;
            if (!$migration instanceof Migration || !method_exists($migration, 'down')) {
                throw new UnexpectedValueException(__('i18n.backend.actions.modules.remove_module_schema_action.invalid_module_migration').$name);
            }
            $migration->down();
            DB::table('migrations')->where('migration', $name)->delete();
        }
    }
}
