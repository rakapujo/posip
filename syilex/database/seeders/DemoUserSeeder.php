<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoUserSeeder extends Seeder
{
    public function run(): void
    {
        $protectedAdmin = User::firstOrCreate(
            ['email' => 'rakapujo@posip.com'],
            [
                'name' => 'Raka Pujo',
                'password' => Hash::make('!890!aBc!@#'),
                'phone' => '000000000000',
                'status' => 'active',
                'is_protected' => true,
            ]
        );
        $protectedAdmin->assignRole('super-admin');

        $superAdmin = User::firstOrCreate(
            ['email' => 'admin@posip.com'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('password'),
                'phone' => '081234567890',
                'status' => 'active',
            ]
        );
        $superAdmin->assignRole('super-admin');

        $admin = User::firstOrCreate(
            ['email' => 'manager@posip.com'],
            [
                'name' => 'Manager',
                'password' => Hash::make('password'),
                'phone' => '081234567891',
                'status' => 'active',
            ]
        );
        $admin->assignRole('admin');

        $kasir = User::firstOrCreate(
            ['email' => 'kasir@posip.com'],
            [
                'name' => 'Kasir 1',
                'password' => Hash::make('password'),
                'phone' => '081234567892',
                'status' => 'active',
            ]
        );
        $kasir->assignRole('kasir');

        $gudang = User::firstOrCreate(
            ['email' => 'gudang@posip.com'],
            [
                'name' => 'Staff Gudang',
                'password' => Hash::make('password'),
                'phone' => '081234567893',
                'status' => 'active',
            ]
        );
        $gudang->assignRole('gudang');

        if ($this->command) {
            $this->command->info('Demo users seeded successfully!');
            $this->command->table(
                ['Email', 'Role', 'Password'],
                [
                    ['admin@posip.com', 'super-admin', 'password'],
                    ['manager@posip.com', 'admin', 'password'],
                    ['kasir@posip.com', 'kasir', 'password'],
                    ['gudang@posip.com', 'gudang', 'password'],
                ]
            );
        }
    }
}
