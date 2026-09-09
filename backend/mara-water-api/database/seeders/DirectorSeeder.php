<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Role;
use App\Models\Department;
use Illuminate\Support\Facades\Hash;

class DirectorSeeder extends Seeder
{
    public function run()
    {
        // Create Admin department if it doesn't exist
        $department = Department::firstOrCreate(
            ['code' => 'ADMIN'],
            [
                'name' => 'Administration',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // Get Admin role
        $role = Role::where('code', 'ADMIN')->first();

        if (!$role) {
            $this->command->error('Admin role not found!');
            return;
        }

        // Create director user
        $user = User::firstOrCreate(
            ['email' => 'director@marawater.com'],
            [
                'phone' => '+254700000000',
                'password_hash' => Hash::make('Admin@2024'),
                'first_name' => 'Managing',
                'last_name' => 'Director',
                'status' => 'active',
                'role_id' => $role->id,
                'department_id' => $department->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $this->command->info('Director user created successfully!');
        $this->command->info('Email: director@marawater.com');
        $this->command->info('Password: Admin@2024');
    }
}
