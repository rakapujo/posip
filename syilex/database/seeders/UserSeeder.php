<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            DemoUserSeeder::class,
        ]);

        $this->command?->info('Users and roles seeded successfully!');
    }
}
