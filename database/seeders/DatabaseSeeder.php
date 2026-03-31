<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
        ]);

        $user = User::factory()->create([
            'salutation' => 'Herr',
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'test@example.com',
        ]);

        $superAdmin = Role::query()
            ->where('name', 'nubos:super-admin')
            ->where('scope', 'platform')
            ->firstOrFail();

        $user->assignRole($superAdmin);
    }
}
