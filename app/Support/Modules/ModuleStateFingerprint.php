<?php

declare(strict_types=1);

namespace App\Support\Modules;

use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

class ModuleStateFingerprint
{
    public function __construct(private readonly ConnectionResolverInterface $database) {}

    public function current(): ?string
    {
        try {
            $modules = $this->database->connection()
                ->table('modules')
                ->orderBy('name')
                ->get(['name', 'version', 'disabled', 'installed_at']);
        } catch (Throwable $exception) {
            Log::warning('The module state could not be read; the Temporal worker keeps its current modules.', ['exception' => $exception]);

            return null;
        }

        $installed = base_path('vendor/composer/installed.json');
        $packages = is_file($installed) ? (string) hash_file('xxh128', $installed) : '';

        return hash('xxh128', $packages.json_encode($modules, JSON_THROW_ON_ERROR));
    }
}
