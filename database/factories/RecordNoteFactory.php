<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CustomRecord;
use App\Models\RecordNote;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecordNote>
 */
class RecordNoteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'record_id' => CustomRecord::factory(),
            'author_id' => User::factory(),
            'body' => fake()->paragraph(),
        ];
    }

    public function withoutAuthor(): static
    {
        return $this->state(fn (array $attributes): array => [
            'author_id' => null,
        ]);
    }
}
