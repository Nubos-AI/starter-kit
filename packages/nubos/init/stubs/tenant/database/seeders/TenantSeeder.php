<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;

class TenantSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->where('email', 'test@example.com')->first();

        if (! $user) {
            return;
        }

        $tenant = Tenant::query()->create([
            'name' => 'Acme Corp',
            'slug' => 'acme-corp',
        ]);
        $tenant->members()->attach($user->id, ['role' => 'owner']);

        $member = User::factory()->create();
        $tenant->members()->attach($member->id, ['role' => 'member']);
    }
}
