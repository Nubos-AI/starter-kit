<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Team> */
class TeamFactory extends Factory
{
    protected $model = Team::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->words(2, true);

        return [
            'workspace_id' => Workspace::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'owner_id' => User::factory(),
        ];
    }
}
