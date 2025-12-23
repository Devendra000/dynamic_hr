<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RoleController extends Controller
{
    /**
     * Display a listing of roles.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = $request->get('per_page', 15);
            $search = $request->get('search');

            $roles = Role::with('permissions')
                ->when($search, function ($query, $search) {
                    return $query->where('name', 'like', "%{$search}%");
                })
                ->paginate($perPage);

            return response()->json([
                'success' => true,
                'message' => 'Roles retrieved successfully',
                'data' => [
                    'roles' => $roles->items(),
                    'pagination' => [
                        'total' => $roles->total(),
                        'per_page' => $roles->perPage(),
                        'current_page' => $roles->currentPage(),
                        'last_page' => $roles->lastPage(),
                        'from' => $roles->firstItem(),
                        'to' => $roles->lastItem(),
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve roles', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve roles',
                'data' => config('app.debug') ? ['error' => $e->getMessage()] : null
            ], 500);
        }
    }

    /**
     * Store a newly created role.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255|unique:roles,name',
                'permissions' => 'nullable|array',
                'permissions.*' => 'exists:permissions,name'
            ]);

            DB::beginTransaction();

            $role = Role::create([
                'name' => strtolower($validated['name']),
                'guard_name' => 'api'
            ]);

            if (isset($validated['permissions']) && !empty($validated['permissions'])) {
                $role->givePermissionTo($validated['permissions']);
            }

            DB::commit();

            Log::info('Role created successfully', [
                'role_id' => $role->id,
                'role_name' => $role->name,
                'created_by' => auth()->id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Role created successfully',
                'data' => $role->load('permissions')
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to create role', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create role',
                'data' => config('app.debug') ? ['error' => $e->getMessage()] : null
            ], 500);
        }
    }

    /**
     * Display the specified role.
     */
    public function show(string $id): JsonResponse
    {
        try {
            $role = Role::with(['permissions', 'users'])->findOrFail($id);

            return response()->json([
                'success' => true,
                'message' => 'Role retrieved successfully',
                'data' => [
                    'role' => $role,
                    'permissions' => $role->permissions->pluck('name'),
                    'users_count' => $role->users->count()
                ]
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Role not found',
                'error_code' => 'ROLE_NOT_FOUND'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve role', [
                'role_id' => $id,
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve role',
                'data' => config('app.debug') ? ['error' => $e->getMessage()] : null
            ], 500);
        }
    }

    /**
     * Update the specified role.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $role = Role::findOrFail($id);

            // Prevent updating system roles
            if (in_array($role->name, ['admin', 'hr', 'employee'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'System roles cannot be modified',
                    'error_code' => 'SYSTEM_ROLE_PROTECTED'
                ], 403);
            }

            $validated = $request->validate([
                'name' => ['sometimes', 'string', 'max:255', Rule::unique('roles')->ignore($role->id)],
            ]);

            DB::beginTransaction();

            if (isset($validated['name'])) {
                $role->update(['name' => strtolower($validated['name'])]);
            }

            DB::commit();

            Log::info('Role updated successfully', [
                'role_id' => $role->id,
                'role_name' => $role->name,
                'updated_by' => auth()->id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Role updated successfully',
                'data' => $role->load('permissions')
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Role not found',
                'error_code' => 'ROLE_NOT_FOUND'
            ], 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to update role', [
                'role_id' => $id,
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update role',
                'data' => config('app.debug') ? ['error' => $e->getMessage()] : null
            ], 500);
        }
    }

    /**
     * Remove the specified role.
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $role = Role::findOrFail($id);

            // Prevent deleting system roles
            if (in_array($role->name, ['admin', 'hr', 'employee'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'System roles cannot be deleted',
                    'error_code' => 'SYSTEM_ROLE_PROTECTED'
                ], 403);
            }

            // Check if role is assigned to any users
            if ($role->users()->count() > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete role that is assigned to users',
                    'error_code' => 'ROLE_IN_USE',
                    'data' => [
                        'users_count' => $role->users()->count()
                    ]
                ], 422);
            }

            DB::beginTransaction();

            $roleName = $role->name;
            $role->delete();

            DB::commit();

            Log::info('Role deleted successfully', [
                'role_name' => $roleName,
                'deleted_by' => auth()->id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Role deleted successfully'
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Role not found',
                'error_code' => 'ROLE_NOT_FOUND'
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to delete role', [
                'role_id' => $id,
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete role',
                'data' => config('app.debug') ? ['error' => $e->getMessage()] : null
            ], 500);
        }
    }

    /**
     * Assign permission to role.
     */
    public function assignPermission(Request $request, string $id): JsonResponse
    {
        try {
            $role = Role::findOrFail($id);

            $validated = $request->validate([
                'permissions' => 'required|array',
                'permissions.*' => 'exists:permissions,name'
            ]);

            DB::beginTransaction();

            $role->givePermissionTo($validated['permissions']);

            DB::commit();

            Log::info('Permissions assigned to role', [
                'role_id' => $role->id,
                'role_name' => $role->name,
                'permissions' => $validated['permissions'],
                'assigned_by' => auth()->id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Permissions assigned successfully',
                'data' => $role->load('permissions')
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Role not found',
                'error_code' => 'ROLE_NOT_FOUND'
            ], 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to assign permissions to role', [
                'role_id' => $id,
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to assign permissions',
                'data' => config('app.debug') ? ['error' => $e->getMessage()] : null
            ], 500);
        }
    }

    /**
     * Remove permission from role.
     */
    public function removePermission(string $id, string $permission): JsonResponse
    {
        try {
            $role = Role::findOrFail($id);

            // Check if permission exists
            $permissionModel = Permission::where('name', $permission)
                ->where('guard_name', 'api')
                ->firstOrFail();

            DB::beginTransaction();

            $role->revokePermissionTo($permission);

            DB::commit();

            Log::info('Permission removed from role', [
                'role_id' => $role->id,
                'role_name' => $role->name,
                'permission' => $permission,
                'removed_by' => auth()->id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Permission removed successfully',
                'data' => $role->load('permissions')
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Role or permission not found',
                'error_code' => 'NOT_FOUND'
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to remove permission from role', [
                'role_id' => $id,
                'permission' => $permission,
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to remove permission',
                'data' => config('app.debug') ? ['error' => $e->getMessage()] : null
            ], 500);
        }
    }

    /**
     * Get all permissions.
     */
    public function permissions(): JsonResponse
    {
        try {
            $permissions = Permission::where('guard_name', 'api')
                ->orderBy('name')
                ->get();

            // Group permissions by category
            $grouped = $permissions->groupBy(function ($permission) {
                $parts = explode('-', $permission->name);
                return count($parts) > 1 ? $parts[1] : 'general';
            });

            return response()->json([
                'success' => true,
                'message' => 'Permissions retrieved successfully',
                'data' => [
                    'permissions' => $permissions,
                    'grouped' => $grouped,
                    'total' => $permissions->count()
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve permissions', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve permissions',
                'data' => config('app.debug') ? ['error' => $e->getMessage()] : null
            ], 500);
        }
    }

    /**
     * Create a new permission.
     */
    public function createPermission(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255|unique:permissions,name',
                'description' => 'nullable|string|max:500'
            ]);

            $permission = Permission::create([
                'name' => strtolower($validated['name']),
                'guard_name' => 'api'
            ]);

            Log::info('Permission created successfully', [
                'permission_id' => $permission->id,
                'permission_name' => $permission->name,
                'created_by' => auth()->id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Permission created successfully',
                'data' => $permission
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Failed to create permission', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create permission',
                'data' => config('app.debug') ? ['error' => $e->getMessage()] : null
            ], 500);
        }
    }

    /**
     * Delete a permission.
     */
    public function deletePermission(string $id): JsonResponse
    {
        try {
            $permission = Permission::findOrFail($id);

            // Check if permission is assigned to any role
            if ($permission->roles()->count() > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete permission that is assigned to roles',
                    'error_code' => 'PERMISSION_IN_USE',
                    'data' => [
                        'roles_count' => $permission->roles()->count(),
                        'roles' => $permission->roles->pluck('name')
                    ]
                ], 422);
            }

            $permissionName = $permission->name;
            $permission->delete();

            Log::info('Permission deleted successfully', [
                'permission_name' => $permissionName,
                'deleted_by' => auth()->id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Permission deleted successfully'
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Permission not found',
                'error_code' => 'PERMISSION_NOT_FOUND'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to delete permission', [
                'permission_id' => $id,
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete permission',
                'data' => config('app.debug') ? ['error' => $e->getMessage()] : null
            ], 500);
        }
    }

    /**
     * Get role statistics.
     */
    public function stats(): JsonResponse
    {
        try {
            $stats = [
                'total_roles' => Role::count(),
                'total_permissions' => Permission::count(),
                'roles_with_users' => Role::has('users')->count(),
                'system_roles' => Role::whereIn('name', ['admin', 'hr', 'employee'])->count(),
                'custom_roles' => Role::whereNotIn('name', ['admin', 'hr', 'employee'])->count(),
                'role_distribution' => Role::withCount('users')
                    ->get()
                    ->map(function ($role) {
                        return [
                            'name' => $role->name,
                            'users_count' => $role->users_count,
                            'permissions_count' => $role->permissions->count()
                        ];
                    })
            ];

            return response()->json([
                'success' => true,
                'message' => 'Role statistics retrieved successfully',
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve role statistics', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve statistics',
                'data' => config('app.debug') ? ['error' => $e->getMessage()] : null
            ], 500);
        }
    }

    /**
     * Get admin dashboard data.
     */
    public function dashboard(): JsonResponse
    {
        try {
            $data = [
                'total_users' => \App\Models\User::count(),
                'active_users' => \App\Models\User::where('status', 'active')->count(),
                'total_roles' => Role::count(),
                'total_permissions' => Permission::count(),
                'recent_users' => \App\Models\User::latest()->take(5)->get(),
                'role_distribution' => Role::withCount('users')->get()
            ];

            return response()->json([
                'success' => true,
                'message' => 'Dashboard data retrieved successfully',
                'data' => $data
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve dashboard data', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve dashboard data',
                'data' => config('app.debug') ? ['error' => $e->getMessage()] : null
            ], 500);
        }
    }
}