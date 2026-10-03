<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

class BuildApiSchema extends Command
{
    protected $signature = 'api:build-schema';

    protected $description = 'Clear the cached OpenAPI document and export a fresh one to disk.';

    public function handle(): int
    {
        if (is_string(config('scramble.cache.store')) && $this->call('scramble:clear') !== self::SUCCESS) {
            $this->error('Failed to clear the cached OpenAPI document.');

            return self::FAILURE;
        }

        if ($this->call('scramble:export') !== self::SUCCESS) {
            $this->error('Failed to export the OpenAPI document.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
