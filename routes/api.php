<?php

use App\Http\Controllers\AuthenticationController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\Admin\EmployeeController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::get('/status', function () {
    return response()->json(['status' => 'API is running']);
});

// Public authentication routes
Route::group([
    'middleware' => ['api', 'rate.limit.auth'],
    'prefix' => 'auth'
], function () {
    Route::post('/register', [AuthenticationController::class, 'register'])->name('auth.register');
    Route::post('/login', [AuthenticationController::class, 'login'])->name('auth.login');
});

// Protected authentication routes
Route::group([
    'middleware' => ['api', 'auth:api'],
    'prefix' => 'auth'
], function () {
    Route::post('/logout', [AuthenticationController::class, 'logout'])->name('auth.logout');
    Route::post('/refresh', [AuthenticationController::class, 'refresh'])->name('auth.refresh');
    Route::get('/me', [AuthenticationController::class, 'me'])->name('auth.me');
    Route::get('/validate', [AuthenticationController::class, 'validateToken'])->name('auth.validate');
});

// ADMIN ROUTES
Route::group([
    'middleware' => ['api', 'auth:api', 'role:admin'],
    'prefix' => 'admin'
], function () {
    
    // 📊 Dashboard
    Route::get('/dashboard', [RoleController::class, 'dashboard'])->name('admin.dashboard');
    
    // 👥 User Management
    Route::prefix('users')->group(function () {
        Route::get('/', [UserManagementController::class, 'index'])->name('admin.users.index');
        Route::post('/', [UserManagementController::class, 'store'])->name('admin.users.store');
        Route::get('/{id}', [UserManagementController::class, 'show'])->name('admin.users.show');
        Route::put('/{id}', [UserManagementController::class, 'update'])->name('admin.users.update');
        Route::delete('/{id}', [UserManagementController::class, 'destroy'])->name('admin.users.destroy');
        
        // Assign/Remove roles
        Route::post('/{id}/roles', [UserManagementController::class, 'assignRole'])->name('admin.users.assign-role');
        Route::delete('/{id}/roles/{role}', [UserManagementController::class, 'removeRole'])->name('admin.users.remove-role');
        
        // Assign/Remove permissions
        Route::post('/{id}/permissions', [UserManagementController::class, 'assignPermission'])->name('admin.users.assign-permission');
        Route::delete('/{id}/permissions/{permission}', [UserManagementController::class, 'removePermission'])->name('admin.users.remove-permission');
    });
    
    // 🎭 Role Management
    Route::prefix('roles')->group(function () {
        Route::get('/', [RoleController::class, 'index'])->name('admin.roles.index');
        Route::post('/', [RoleController::class, 'store'])->name('admin.roles.store');
        Route::get('/{id}', [RoleController::class, 'show'])->name('admin.roles.show');
        Route::put('/{id}', [RoleController::class, 'update'])->name('admin.roles.update');
        Route::delete('/{id}', [RoleController::class, 'destroy'])->name('admin.roles.destroy');
        
        // Assign/Remove permissions to role
        Route::post('/{id}/permissions', [RoleController::class, 'assignPermission'])->name('admin.roles.assign-permission');
        Route::delete('/{id}/permissions/{permission}', [RoleController::class, 'removePermission'])->name('admin.roles.remove-permission');
    });
    
    // 🔐 Permission Management
    Route::prefix('permissions')->group(function () {
        Route::get('/', [RoleController::class, 'permissions'])->name('admin.permissions.index');
        Route::post('/', [RoleController::class, 'createPermission'])->name('admin.permissions.store');
        Route::delete('/{id}', [RoleController::class, 'deletePermission'])->name('admin.permissions.destroy');
    });
    
    // 👔 Employee Management (Admin only)
    Route::prefix('employees')->group(function () {
        Route::get('/', [EmployeeController::class, 'index'])->name('admin.employees.index');
        Route::post('/', [EmployeeController::class, 'store'])->name('admin.employees.store');
        Route::get('/{id}', [EmployeeController::class, 'show'])->name('admin.employees.show');
        Route::put('/{id}', [EmployeeController::class, 'update'])->name('admin.employees.update');
        Route::delete('/{id}', [EmployeeController::class, 'destroy'])->name('admin.employees.destroy');
        Route::patch('/{id}/status', [EmployeeController::class, 'updateStatus'])->name('admin.employees.status');
    });
    
    // 📈 System Stats
    Route::get('/stats', [RoleController::class, 'stats'])->name('admin.stats');
});

// HR ROUTES
Route::group([
    'middleware' => ['api', 'auth:api', 'role:admin,hr'],
    'prefix' => 'hr'
], function () {
    // HR can view employees but with limited actions
    Route::get('/employees', [EmployeeController::class, 'index'])->name('hr.employees.index');
    Route::get('/employees/{id}', [EmployeeController::class, 'show'])->name('hr.employees.show');
    Route::put('/employees/{id}', [EmployeeController::class, 'update'])->name('hr.employees.update');
});

// EMPLOYEE ROUTES
Route::group([
    'middleware' => ['api', 'auth:api'],
    'prefix' => 'employee'
], function () {
    // Employee can only view their own data
    Route::get('/profile', [AuthenticationController::class, 'me'])->name('employee.profile');
});