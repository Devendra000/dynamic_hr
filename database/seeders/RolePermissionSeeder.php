<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = [
            ['name' => 'admin', 'guard_name' => 'api'],
            ['name' => 'hr', 'guard_name' => 'api'],
            ['name' => 'employee', 'guard_name' => 'api'],
        ];

        //test permissions to be expanded later
        $permissions = [
            ['name' => 'manage users', 'guard_name' => 'api'],
            ['name' => 'view reports', 'guard_name' => 'api'],
        ];

        DB::table('roles')->insert($roles);
        DB::table('permissions')->insert($permissions);
    }
}
