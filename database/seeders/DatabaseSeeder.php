<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            TenantSeeder::class,
        ]);

        // User::factory(10)->create();

        User::factory()->create([
            'salutation' => 'Herr',
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'test@example.com',
        ]);
    }
}
