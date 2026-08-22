<?php

namespace Modules\AccessControl\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\User\Models\User;
use Illuminate\Support\Facades\Hash;
use Modules\AccessControl\Models\Role;

class AdminUserSeeder extends Seeder
{
    public function run()
    {
        // Make sure the admin role exists
        $adminRole = Role::where('slug', 'admin')->firstOrFail();

        // Create or update admin user
        $admin = User::updateOrCreate(
            ['email' => 'admin@admin.com'],
            [
                'name' => 'Admin',
                'password' => Hash::make('AIProj@techtrack'),
                'email_verified_at' => now(),
            ]
        );

        // Assign admin role
        $admin->roles()->syncWithoutDetaching([$adminRole->id]);


        // Create or update second user
        $user = User::updateOrCreate(
            ['email' => 'user123@gmail.com'],
            [
                'name' => 'User 123',
                'password' => Hash::make('AIProj@techtrack'),
                'email_verified_at' => now(),
            ]
        );
    }
}