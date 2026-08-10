<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Ensure the admin role exists (in case RoleSeeder hasn't run)
        $adminRole = Role::firstOrCreate(['name' => 'admin']);

        // Create or update the admin user
        $admin = User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Admin User',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );

        // Assign the admin role using Spatie's method
        $admin->assignRole($adminRole);

        $this->command->info('Admin user seeded: admin@example.com / password');
    }
}