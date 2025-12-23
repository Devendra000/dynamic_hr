<?php

namespace App\Http\Controllers;

use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RoleController extends Controller
{
    use ApiResponse;
    /**
     * Display a listing of roles.
     *
     * @OA\Get(
     *     path="/admin/roles",
     *     tags={"Role Management"},
     *     summary="List all roles",
     *     description="Get paginated list of roles with permissions",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="per_page",
     *         in="query",
     *         description="Items per page",
     *         required=false,
     *         @OA\Schema(type="integer", default=15)
     *     ),
     *     @OA\Parameter(
     *         name="search",
     *         in="query",
     *         description="Search by role name",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Roles retrieved successfully"
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden")
     * )
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

            return $this->successResponse('Roles retrieved successfully', [
                'roles' => $roles->items(),
                'pagination' => [
                    'total' => $roles->total(),
                    'per_page' => $roles->perPage(),
                    'current_page' => $roles->currentPage(),
                    'last_page' => $roles->lastPage(),
                    'from' => $roles->firstItem(),
                    'to' => $roles->lastItem(),
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve roles', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return $this->serverErrorResponse(
                'Failed to retrieve roles',
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Store a newly created role.
     *
     * @OA\Post(
     *     path="/admin/roles",
     *     tags={"Role Management"},
     *     summary="Create new role",
     *     description="Create a new role with optional permissions",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name"},
     *             @OA\Property(property="name", type="string", example="manager"),
     *             @OA\Property(property="permissions", type="array", @OA\Items(type="string"), example={"view-employees", "manage-attendance"})
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Role created successfully"
     *     ),
     *     @OA\Response(response=422, description="Validation error")
     * )
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

            return $this->successResponse('Role created successfully', $role->load('permissions'), 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->validationErrorResponse('Validation failed', $e->errors());
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to create role', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return $this->serverErrorResponse(
                'Failed to create role',
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Display the specified role.
     *
     * @OA\Get(
     *     path="/admin/roles/{id}",
     *     tags={"Role Management"},
     *     summary="Get role details",
     *     description="Get detailed information about a specific role",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Role ID",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Role retrieved successfully"
     *     ),
     *     @OA\Response(response=404, description="Role not found")
     * )
     */
    public function show(string $id): JsonResponse
    {
        try {
            $role = Role::with(['permissions', 'users'])->findOrFail($id);

            return $this->successResponse('Role retrieved successfully', [
                'role' => $role,
                'permissions' => $role->permissions->pluck('name'),
                'users_count' => $role->users->count()
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->notFoundResponse('Role not found');
        } catch (\Exception $e) {
            Log::error('Failed to retrieve role', [
                'role_id' => $id,
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return $this->serverErrorResponse(
                'Failed to retrieve role',
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Update the specified role.
     *
     * @OA\Put(
     *     path="/admin/roles/{id}",
     *     tags={"Role Management"},
     *     summary="Update role",
     *     description="Update role name (system roles cannot be modified)",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Role ID",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="name", type="string", example="senior-manager")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Role updated successfully"
     *     ),
     *     @OA\Response(response=403, description="System role protected"),
     *     @OA\Response(response=404, description="Role not found")
     * )
     */
    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $role = Role::findOrFail($id);

            // Prevent updating system roles
            if (in_array($role->name, ['admin', 'hr', 'employee'])) {
                return $this->forbiddenResponse('System roles cannot be modified');
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

            return $this->successResponse('Role updated successfully', $role->load('permissions'));

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->notFoundResponse('Role not found');
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->validationErrorResponse('Validation failed', $e->errors());
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to update role', [
                'role_id' => $id,
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return $this->serverErrorResponse(
                'Failed to update role',
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Remove the specified role.
     *
     * @OA\Delete(
     *     path="/admin/roles/{id}",
     *     tags={"Role Management"},
     *     summary="Delete role",
     *     description="Delete a role (system roles and roles in use cannot be deleted)",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Role ID",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Role deleted successfully"
     *     ),
     *     @OA\Response(response=403, description="System role protected"),
     *     @OA\Response(response=422, description="Role is in use")
     * )
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $role = Role::findOrFail($id);

            // Prevent deleting system roles
            if (in_array($role->name, ['admin', 'hr', 'employee'])) {
                return $this->forbiddenResponse('System roles cannot be deleted');
            }

            // Check if role is assigned to any users
            if ($role->users()->count() > 0) {
                return $this->validationErrorResponse(
                    'Cannot delete role that is assigned to users',
                    ['users_count' => $role->users()->count()]
                );
            }

            DB::beginTransaction();

            $roleName = $role->name;
            $role->delete();

            DB::commit();

            Log::info('Role deleted successfully', [
                'role_name' => $roleName,
                'deleted_by' => auth()->id()
            ]);

            return $this->successResponse('Role deleted successfully');

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->notFoundResponse('Role not found');
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to delete role', [
                'role_id' => $id,
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return $this->serverErrorResponse(
                'Failed to delete role',
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Assign permission to role.
     *
     * @OA\Post(
     *     path="/admin/roles/{id}/permissions",
     *     tags={"Role Management"},
     *     summary="Assign permissions to role",
     *     description="Assign one or more permissions to a role",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Role ID",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"permissions"},
     *             @OA\Property(property="permissions", type="array", @OA\Items(type="string"), example={"view-employees", "edit-employees"})
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Permissions assigned successfully"
     *     )
     * )
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

            return $this->successResponse('Permissions assigned successfully', $role->load('permissions'));

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->notFoundResponse('Role not found');
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->validationErrorResponse('Validation failed', $e->errors());
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to assign permissions to role', [
                'role_id' => $id,
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return $this->serverErrorResponse(
                'Failed to assign permissions',
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Remove permission from role.
     *
     * @OA\Delete(
     *     path="/admin/roles/{id}/permissions/{permission}",
     *     tags={"Role Management"},
     *     summary="Remove permission from role",
     *     description="Remove a specific permission from a role",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Role ID",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="permission",
     *         in="path",
     *         description="Permission name",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Permission removed successfully"
     *     )
     * )
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

            return $this->successResponse('Permission removed successfully', $role->load('permissions'));

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->notFoundResponse('Role or permission not found');
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to remove permission from role', [
                'role_id' => $id,
                'permission' => $permission,
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return $this->serverErrorResponse(
                'Failed to remove permission',
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Get all permissions.
     *
     * @OA\Get(
     *     path="/admin/permissions",
     *     tags={"Permission Management"},
     *     summary="List all permissions",
     *     description="Get all available permissions grouped by category",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Permissions retrieved successfully"
     *     )
     * )
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

            return $this->successResponse('Permissions retrieved successfully', [
                'permissions' => $permissions,
                'grouped' => $grouped,
                'total' => $permissions->count()
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve permissions', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return $this->serverErrorResponse(
                'Failed to retrieve permissions',
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Create a new permission.
     *
     * @OA\Post(
     *     path="/admin/permissions",
     *     tags={"Permission Management"},
     *     summary="Create new permission",
     *     description="Create a new permission",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name"},
     *             @OA\Property(property="name", type="string", example="manage-projects"),
     *             @OA\Property(property="description", type="string", example="Can manage project assignments")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Permission created successfully"
     *     )
     * )
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

            return $this->successResponse('Permission created successfully', $permission, 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->validationErrorResponse('Validation failed', $e->errors());
        } catch (\Exception $e) {
            Log::error('Failed to create permission', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return $this->serverErrorResponse(
                'Failed to create permission',
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Delete a permission.
     *
     * @OA\Delete(
     *     path="/admin/permissions/{id}",
     *     tags={"Permission Management"},
     *     summary="Delete permission",
     *     description="Delete a permission (cannot delete if assigned to roles)",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Permission ID",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Permission deleted successfully"
     *     ),
     *     @OA\Response(response=422, description="Permission in use")
     * )
     */
    public function deletePermission(string $id): JsonResponse
    {
        try {
            $permission = Permission::findOrFail($id);

            // Check if permission is assigned to any role
            if ($permission->roles()->count() > 0) {
                return $this->validationErrorResponse(
                    'Cannot delete permission that is assigned to roles',
                    [
                        'roles_count' => $permission->roles()->count(),
                        'roles' => $permission->roles->pluck('name')
                    ]
                );
            }

            $permissionName = $permission->name;
            $permission->delete();

            Log::info('Permission deleted successfully', [
                'permission_name' => $permissionName,
                'deleted_by' => auth()->id()
            ]);

            return $this->successResponse('Permission deleted successfully');

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->notFoundResponse('Permission not found');
        } catch (\Exception $e) {
            Log::error('Failed to delete permission', [
                'permission_id' => $id,
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return $this->serverErrorResponse(
                'Failed to delete permission',
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Get role statistics.
     *
     * @OA\Get(
     *     path="/admin/stats",
     *     tags={"Dashboard"},
     *     summary="Get role statistics",
     *     description="Get comprehensive role and permission statistics",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Statistics retrieved successfully"
     *     )
     * )
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

            return $this->successResponse('Role statistics retrieved successfully', $stats);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve role statistics', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return $this->serverErrorResponse(
                'Failed to retrieve statistics',
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Get admin dashboard data.
     *
     * @OA\Get(
     *     path="/admin/dashboard",
     *     tags={"Dashboard"},
     *     summary="Get admin dashboard",
     *     description="Get admin dashboard with users, roles, and system statistics",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Dashboard data retrieved successfully"
     *     )
     * )
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

            return $this->successResponse('Dashboard data retrieved successfully', $data);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve dashboard data', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return $this->serverErrorResponse(
                'Failed to retrieve dashboard data',
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }
}