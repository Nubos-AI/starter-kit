<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class TeamSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->where('email', 'test@example.com')->first();

        if (! $user) {
            return;
        }

        // Personal team
        $personal = Team::query()->create([
            'owner_id' => $user->id,
            'name' => $user->name . '\'s Team',
            'slug' => Str::slug($user->name),
            'is_personal' => true,
        ]);
        $personal->members()->attach($user->id, ['role' => 'owner']);

        // Demo team
        $demo = Team::query()->create([
            'owner_id' => $user->id,
            'name' => 'Acme Corp',
            'slug' => 'acme-corp',
            'is_personal' => false,
        ]);
        $demo->members()->attach($user->id, ['role' => 'owner']);

        // Additional member
        $member = User::factory()->create();
        $demo->members()->attach($member->id, ['role' => 'member']);
    }
}
