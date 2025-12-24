<?php

use App\Http\Controllers\Admin\FormSubmissionAdminController;
use App\Http\Controllers\Admin\EmployeeController;
use App\Http\Controllers\AuthenticationController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\Employee\FormSubmissionController;
use App\Http\Controllers\ExcelController;
use App\Http\Controllers\FormTemplateController;
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

Route::get('/status', function () {
    return response()->json(['status' => 'API is running']);
});

// Public authentication routes
Route::group([
    'middleware' => ['api', 'rate.limit.auth'],
    'prefix' => 'auth'
], function () {
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
    
    // Dashboard
    Route::get('/dashboard', [RoleController::class, 'dashboard'])->name('admin.dashboard');
    Route::get('/stats', [RoleController::class, 'stats'])->name('admin.stats');
    
    // User Management
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
    
    // Role Management
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

    // Permission Management
    Route::prefix('permissions')->group(function () {
        Route::get('/', [RoleController::class, 'permissions'])->name('admin.permissions.index');
        Route::post('/', [RoleController::class, 'createPermission'])->name('admin.permissions.store');
        Route::delete('/{id}', [RoleController::class, 'deletePermission'])->name('admin.permissions.destroy');
    });
    
    // Form Template Management
    Route::prefix('form-templates')->group(function () {
        Route::get('/', [FormTemplateController::class, 'index'])->name('admin.form-templates.index');
        Route::post('/', [FormTemplateController::class, 'store'])->name('admin.form-templates.store');
        Route::get('/{id}', [FormTemplateController::class, 'show'])->name('admin.form-templates.show');
        Route::put('/{id}', [FormTemplateController::class, 'update'])->name('admin.form-templates.update');
        Route::delete('/{id}', [FormTemplateController::class, 'destroy'])->name('admin.form-templates.destroy');
        Route::post('/{id}/duplicate', [FormTemplateController::class, 'duplicate'])->name('admin.form-templates.duplicate');
        
        // Field management
        Route::post('/{id}/fields', [FormTemplateController::class, 'addField'])->name('admin.form-templates.add-field');
        Route::put('/{id}/fields/{fieldId}', [FormTemplateController::class, 'updateField'])->name('admin.form-templates.update-field');
        Route::delete('/{id}/fields/{fieldId}', [FormTemplateController::class, 'removeField'])->name('admin.form-templates.remove-field');
    });
    
    // Form Submission Management (Admin/HR)
    Route::prefix('submissions')->group(function () {
        Route::get('/', [FormSubmissionAdminController::class, 'index'])->name('admin.submissions.index');
        Route::get('/stats', [FormSubmissionAdminController::class, 'stats'])->name('admin.submissions.stats');
        
        // Excel Export/Import
        Route::get('/export', [ExcelController::class, 'exportSubmissions'])->name('admin.submissions.export');
        Route::post('/import', [ExcelController::class, 'importSubmissions'])->name('admin.submissions.import');
        Route::post('/import/validate', [ExcelController::class, 'validateImport'])->name('admin.submissions.import-validate');
        
        Route::get('/{id}', [FormSubmissionAdminController::class, 'show'])->name('admin.submissions.show');
        Route::put('/{id}/status', [FormSubmissionAdminController::class, 'updateStatus'])->name('admin.submissions.update-status');
        Route::post('/{id}/comments', [FormSubmissionAdminController::class, 'addComment'])->name('admin.submissions.add-comment');
    });
    
    // Form Templates - Excel Template Download
    Route::get('/form-templates/{id}/excel-template', [ExcelController::class, 'downloadTemplate'])->name('admin.form-templates.excel-template');

});

// HR ROUTES (Employee Management)
Route::group([
    'middleware' => ['api', 'auth:api', 'role:admin|hr'],
    'prefix' => 'hr'
], function () {
    Route::prefix('employees')->group(function () {
        Route::get('/', [EmployeeController::class, 'index'])->name('hr.employees.index');
        Route::post('/', [EmployeeController::class, 'store'])->name('hr.employees.store');
        Route::get('/stats', [EmployeeController::class, 'stats'])->name('hr.employees.stats');
        Route::get('/{id}', [EmployeeController::class, 'show'])->name('hr.employees.show');
        Route::put('/{id}', [EmployeeController::class, 'update'])->name('hr.employees.update');
        Route::delete('/{id}', [EmployeeController::class, 'destroy'])->name('hr.employees.destroy');
        Route::patch('/{id}/status', [EmployeeController::class, 'updateStatus'])->name('hr.employees.update-status');
    });
});

// EMPLOYEE ROUTES (Self-service)
Route::group([
    'middleware' => ['api', 'auth:api'],
    'prefix' => 'employee'
], function () {
    // Employee can only view their own data
    Route::get('/profile', [AuthenticationController::class, 'me'])->name('employee.profile');
    
    // Available Forms
    Route::get('/forms', [FormSubmissionController::class, 'availableForms'])->name('employee.forms');
    
    // Form Submissions
    Route::prefix('submissions')->group(function () {
        Route::get('/', [FormSubmissionController::class, 'index'])->name('employee.submissions.index');
        Route::post('/', [FormSubmissionController::class, 'store'])->name('employee.submissions.store');
        Route::get('/{id}', [FormSubmissionController::class, 'show'])->name('employee.submissions.show');
        Route::put('/{id}', [FormSubmissionController::class, 'update'])->name('employee.submissions.update');
        Route::delete('/{id}', [FormSubmissionController::class, 'destroy'])->name('employee.submissions.destroy');
    });
});
