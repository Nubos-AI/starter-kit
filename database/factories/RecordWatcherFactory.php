<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Watchers\WatcherSource;
use App\Models\CustomRecord;
use App\Models\RecordWatcher;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecordWatcher>
 */
class RecordWatcherFactory extends Factory
{
    public function definition(): array
    {
        return [
            'record_id' => CustomRecord::factory(),
            'user_id' => User::factory(),
            'source' => WatcherSource::Auto,
            'added_by_id' => null,
        ];
    }
}
