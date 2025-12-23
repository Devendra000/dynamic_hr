<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class UserManagementController extends Controller
{
    /**
     * Get all users with their roles and permissions
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->get('per_page', 15);
        $search = $request->get('search');

        $users = User::with(['roles', 'permissions'])
            ->when($search, function ($query, $search) {
                return $query->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            })
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Users retrieved successfully',
            'data' => $users
        ]);
    }

    /**
     * Create a new user
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'role' => 'nullable|string|exists:roles,name'
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => strtolower(trim($validated['email'])),
            'password' => Hash::make($validated['password'])
        ]);

        if (isset($validated['role'])) {
            $user->assignRole($validated['role']);
        }

        return response()->json([
            'success' => true,
            'message' => 'User created successfully',
            'data' => $user->load(['roles', 'permissions'])
        ], 201);
    }

    /**
     * Get a specific user
     */
    public function show(string $id): JsonResponse
    {
        $user = User::with(['roles', 'permissions'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'message' => 'User retrieved successfully',
            'data' => $user
        ]);
    }

    /**
     * Update a user
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $user = User::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => ['sometimes', 'email', Rule::unique('users')->ignore($user->id)],
            'password' => 'sometimes|string|min:8'
        ]);

        if (isset($validated['email'])) {
            $validated['email'] = strtolower(trim($validated['email']));
        }

        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }

        $user->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'User updated successfully',
            'data' => $user->load(['roles', 'permissions'])
        ]);
    }

    /**
     * Delete a user
     */
    public function destroy(string $id): JsonResponse
    {
        $user = User::findOrFail($id);
        
        // Prevent deleting yourself
        if ($user->id === auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot delete your own account'
            ], 403);
        }

        $user->delete();

        return response()->json([
            'success' => true,
            'message' => 'User deleted successfully'
        ]);
    }

    /**
     * Assign role to user
     */
    public function assignRole(Request $request, string $id): JsonResponse
    {
        $user = User::findOrFail($id);
        
        $validated = $request->validate([
            'role' => 'required|string|exists:roles,name'
        ]);

        $user->assignRole($validated['role']);

        return response()->json([
            'success' => true,
            'message' => 'Role assigned successfully',
            'data' => $user->load(['roles', 'permissions'])
        ]);
    }

    /**
     * Remove role from user
     */
    public function removeRole(string $id, string $role): JsonResponse
    {
        $user = User::findOrFail($id);
        $user->removeRole($role);

        return response()->json([
            'success' => true,
            'message' => 'Role removed successfully',
            'data' => $user->load(['roles', 'permissions'])
        ]);
    }

    /**
     * Assign permission to user
     */
    public function assignPermission(Request $request, string $id): JsonResponse
    {
        $user = User::findOrFail($id);
        
        $validated = $request->validate([
            'permission' => 'required|string|exists:permissions,name'
        ]);

        $user->givePermissionTo($validated['permission']);

        return response()->json([
            'success' => true,
            'message' => 'Permission assigned successfully',
            'data' => $user->load(['roles', 'permissions'])
        ]);
    }

    /**
     * Remove permission from user
     */
    public function removePermission(string $id, string $permission): JsonResponse
    {
        $user = User::findOrFail($id);
        $user->revokePermissionTo($permission);

        return response()->json([
            'success' => true,
            'message' => 'Permission removed successfully',
            'data' => $user->load(['roles', 'permissions'])
        ]);
    }
}
