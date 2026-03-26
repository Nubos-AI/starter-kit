<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\{{Model}};
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<{{Model}}>
 */
class {{Model}}Factory extends Factory
{
    protected $model = {{Model}}::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'owner_id' => User::factory(),
            'personal_{{model}}' => false,
        ];
    }

    public function personal(): static
    {
        return $this->state(fn (): array => [
            'personal_{{model}}' => true,
        ]);
    }
}
