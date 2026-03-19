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

        $attributes = [
            'name' => 'Acme Corp',
            'slug' => 'acme-corp',
        ];

        if (config('nubos.database_strategy') === 'multi') {
            $attributes['database'] = 'tenant_acme_corp';
        }

        $tenant = Tenant::query()->create($attributes);
        $tenant->members()->attach($user->id, ['role' => 'owner']);

        if (method_exists($tenant, 'configureDatabaseConnection')) {
            app('App\Services\TenantDatabaseManager')->createDatabase($tenant);
        }

        $member = User::factory()->create();
        $tenant->members()->attach($member->id, ['role' => 'member']);
    }
}
