<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Models\User;

class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $permissions = [
            // User Management
            'manage-users',
            'view-users',
            'create-users',
            'edit-users',
            'delete-users',
            
            // Employee Management
            'manage-employees',
            'view-employees',
            'create-employees',
            'edit-employees',
            'delete-employees',
            
            // Role & Permission Management
            'manage-roles',
            'assign-roles',
            'manage-permissions',
            
            // Department Management
            'manage-departments',
            'view-departments',
            
            // Attendance Management
            'manage-attendance',
            'view-attendance',
            'approve-attendance',
            
            // Leave Management
            'manage-leaves',
            'view-leaves',
            'approve-leaves',
            'reject-leaves',
            
            // Payroll Management
            'manage-payroll',
            'view-payroll',
            'process-payroll',
            
            // Reports
            'view-reports',
            'generate-reports',
            
            // Settings
            'manage-settings',
        ];

        foreach ($permissions as $permission) {
            Permission::create([
                'name' => $permission,
                'guard_name' => 'api'
            ]);
        }

        // ============================================
        // 2️⃣ CREATE ROLES & ASSIGN PERMISSIONS
        // ============================================
        
        // ADMIN ROLE - Full Access
        $adminRole = Role::create([
            'name' => 'admin',
            'guard_name' => 'api'
        ]);
        $adminRole->givePermissionTo(Permission::all());

        // HR ROLE - HR Management
        $hrRole = Role::create([
            'name' => 'hr',
            'guard_name' => 'api'
        ]);
        $hrRole->givePermissionTo([
            'view-users',
            'view-employees',
            'edit-employees',
            'manage-employees',
            'view-departments',
            'manage-attendance',
            'view-attendance',
            'approve-attendance',
            'manage-leaves',
            'view-leaves',
            'approve-leaves',
            'reject-leaves',
            'view-payroll',
            'view-reports',
        ]);

        // EMPLOYEE ROLE - Basic Access
        $employeeRole = Role::create([
            'name' => 'employee',
            'guard_name' => 'api'
        ]);
        $employeeRole->givePermissionTo([
            'view-attendance',
            'view-leaves',
            'view-payroll',
        ]);

        $admin = User::create([
            'name' => 'System Administrator',
            'email' => 'admin@dynamichr.com',
            'password' => Hash::make('Admin@123'),
            'email_verified_at' => now(),
            'phone' => '+1234567890',
            'department' => 'Management',
            'position' => 'System Administrator',
            'employee_id' => 'ADMIN001',
            'hire_date' => now(),
            'status' => 'active',
        ]);
        $admin->assignRole('admin');

        $hr = User::create([
            'name' => 'HR Manager',
            'email' => 'hr@dynamichr.com',
            'password' => Hash::make('HR@123'),
            'email_verified_at' => now(),
            'phone' => '+1234567891',
            'department' => 'Human Resources',
            'position' => 'HR Manager',
            'employee_id' => 'HR001',
            'hire_date' => now(),
            'status' => 'active',
        ]);
        $hr->assignRole('hr');

        $employee = User::create([
            'name' => 'John Doe',
            'email' => 'employee@dynamichr.com',
            'password' => Hash::make('Employee@123'),
            'email_verified_at' => now(),
            'phone' => '+1234567892',
            'department' => 'Engineering',
            'position' => 'Software Engineer',
            'employee_id' => 'EMP001',
            'hire_date' => now(),
            'salary' => 75000.00,
            'status' => 'active',
        ]);
        $employee->assignRole('employee');
    }
}
