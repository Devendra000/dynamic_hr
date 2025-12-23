<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    /**
     * Get all employees with pagination and search
     *
     * @OA\Get(
     *     path="/hr/employees",
     *     tags={"Employee Management"},
     *     summary="List all employees",
     *     description="Get paginated list of employees with filtering options",
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
     *         description="Search by name or email",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="status",
     *         in="query",
     *         description="Filter by status",
     *         required=false,
     *         @OA\Schema(type="string", enum={"active", "inactive", "suspended"})
     *     ),
     *     @OA\Parameter(
     *         name="role",
     *         in="query",
     *         description="Filter by role",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Employees retrieved successfully"
     *     ),
     *     @OA\Response(response=403, description="Forbidden")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->get('per_page', 15);
        $search = $request->get('search');
        $status = $request->get('status'); // active, inactive, suspended
        $role = $request->get('role');

        $employees = User::with(['roles', 'permissions'])
            ->when($search, function ($query, $search) {
                return $query->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            })
            ->when($status, function ($query, $status) {
                return $query->where('status', $status);
            })
            ->when($role, function ($query, $role) {
                return $query->role($role);
            })
            ->latest()
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Employees retrieved successfully',
            'data' => [
                'employees' => $employees->items(),
                'pagination' => [
                    'total' => $employees->total(),
                    'per_page' => $employees->perPage(),
                    'current_page' => $employees->currentPage(),
                    'last_page' => $employees->lastPage(),
                    'from' => $employees->firstItem(),
                    'to' => $employees->lastItem(),
                ]
            ]
        ]);
    }

    /**
     * Create a new employee
     *
     * @OA\Post(
     *     path="/hr/employees",
     *     tags={"Employee Management"},
     *     summary="Create new employee",
     *     description="Create a new employee with all details",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name","email","password","password_confirmation"},
     *             @OA\Property(property="name", type="string", example="John Smith"),
     *             @OA\Property(property="email", type="string", format="email", example="john.smith@dynamichr.com"),
     *             @OA\Property(property="password", type="string", format="password", example="Password123!"),
     *             @OA\Property(property="password_confirmation", type="string", format="password", example="Password123!"),
     *             @OA\Property(property="role", type="string", example="employee"),
     *             @OA\Property(property="phone", type="string", example="+1234567890"),
     *             @OA\Property(property="department", type="string", example="Engineering"),
     *             @OA\Property(property="position", type="string", example="Software Developer"),
     *             @OA\Property(property="employee_id", type="string", example="EMP-001"),
     *             @OA\Property(property="hire_date", type="string", format="date", example="2024-01-15"),
     *             @OA\Property(property="salary", type="number", example=50000),
     *             @OA\Property(property="status", type="string", enum={"active", "inactive", "suspended"}, example="active")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Employee created successfully"
     *     ),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'role' => 'nullable|string|exists:roles,name',
            'phone' => 'nullable|string|max:20',
            'department' => 'nullable|string|max:100',
            'position' => 'nullable|string|max:100',
            'employee_id' => 'nullable|string|unique:users,employee_id',
            'hire_date' => 'nullable|date',
            'salary' => 'nullable|numeric|min:0',
            'status' => 'nullable|in:active,inactive,suspended'
        ]);

        $employee = User::create([
            'name' => $validated['name'],
            'email' => strtolower(trim($validated['email'])),
            'password' => Hash::make($validated['password']),
            'phone' => $validated['phone'] ?? null,
            'department' => $validated['department'] ?? null,
            'position' => $validated['position'] ?? null,
            'employee_id' => $validated['employee_id'] ?? null,
            'hire_date' => $validated['hire_date'] ?? now(),
            'salary' => $validated['salary'] ?? null,
            'status' => $validated['status'] ?? 'active',
        ]);

        // Assign default role if provided
        if (isset($validated['role'])) {
            $employee->assignRole($validated['role']);
        } else {
            $employee->assignRole('employee'); // Default role
        }

        return response()->json([
            'success' => true,
            'message' => 'Employee created successfully',
            'data' => $employee->load(['roles', 'permissions'])
        ], 201);
    }

    /**
     * Get a specific employee
     *
     * @OA\Get(
     *     path="/hr/employees/{id}",
     *     tags={"Employee Management"},
     *     summary="Get employee details",
     *     description="Get detailed information about a specific employee",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Employee ID",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Employee retrieved successfully"
     *     ),
     *     @OA\Response(response=404, description="Employee not found")
     * )
     */
    public function show(string $id): JsonResponse
    {
        $employee = User::with(['roles', 'permissions'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'message' => 'Employee retrieved successfully',
            'data' => [
                'employee' => $employee,
                'roles' => $employee->getRoleNames(),
                'permissions' => $employee->getAllPermissions()->pluck('name'),
            ]
        ]);
    }

    /**
     * Update an employee
     *
     * @OA\Put(
     *     path="/hr/employees/{id}",
     *     tags={"Employee Management"},
     *     summary="Update employee",
     *     description="Update employee information",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Employee ID",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\RequestBody(
     *         required=false,
     *         @OA\JsonContent(
     *             @OA\Property(property="name", type="string", example="John Updated"),
     *             @OA\Property(property="email", type="string", format="email", example="john.updated@dynamichr.com"),
     *             @OA\Property(property="password", type="string", format="password", example="NewPassword123!"),
     *             @OA\Property(property="password_confirmation", type="string", format="password", example="NewPassword123!"),
     *             @OA\Property(property="phone", type="string", example="+1234567890"),
     *             @OA\Property(property="department", type="string", example="Engineering"),
     *             @OA\Property(property="position", type="string", example="Senior Developer"),
     *             @OA\Property(property="employee_id", type="string", example="EMP-001"),
     *             @OA\Property(property="hire_date", type="string", format="date", example="2024-01-15"),
     *             @OA\Property(property="salary", type="number", example=60000),
     *             @OA\Property(property="status", type="string", enum={"active", "inactive", "suspended"})
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Employee updated successfully"
     *     )
     * )
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $employee = User::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => ['sometimes', 'email', Rule::unique('users')->ignore($employee->id)],
            'password' => 'sometimes|string|min:8|confirmed',
            'phone' => 'nullable|string|max:20',
            'department' => 'nullable|string|max:100',
            'position' => 'nullable|string|max:100',
            'employee_id' => ['nullable', 'string', Rule::unique('users')->ignore($employee->id)],
            'hire_date' => 'nullable|date',
            'salary' => 'nullable|numeric|min:0',
            'status' => 'nullable|in:active,inactive,suspended'
        ]);

        if (isset($validated['email'])) {
            $validated['email'] = strtolower(trim($validated['email']));
        }

        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']); // Don't update if not provided
        }

        $employee->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Employee updated successfully',
            'data' => $employee->load(['roles', 'permissions'])
        ]);
    }

    /**
     * Delete an employee
     *
     * @OA\Delete(
     *     path="/hr/employees/{id}",
     *     tags={"Employee Management"},
     *     summary="Delete employee",
     *     description="Delete an employee (cannot delete yourself or admins)",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Employee ID",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Employee deleted successfully"
     *     ),
     *     @OA\Response(response=403, description="Forbidden")
     * )
     */
    public function destroy(string $id): JsonResponse
    {
        $employee = User::findOrFail($id);
        
        // Prevent deleting yourself
        if ($employee->id === auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot delete your own account',
                'error_code' => 'SELF_DELETION_NOT_ALLOWED'
            ], 403);
        }

        // Check if employee has admin role (extra protection)
        if ($employee->hasRole('admin') && !auth()->user()->hasRole('super-admin')) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot delete an admin user',
                'error_code' => 'INSUFFICIENT_PERMISSIONS'
            ], 403);
        }

        $employee->delete();

        return response()->json([
            'success' => true,
            'message' => 'Employee deleted successfully'
        ]);
    }

    /**
     * Update employee status (active, inactive, suspended)
     *
     * @OA\Patch(
     *     path="/hr/employees/{id}/status",
     *     tags={"Employee Management"},
     *     summary="Update employee status",
     *     description="Change employee status (active, inactive, suspended)",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Employee ID",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"status"},
     *             @OA\Property(property="status", type="string", enum={"active", "inactive", "suspended"}, example="active"),
     *             @OA\Property(property="reason", type="string", example="Extended leave")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Employee status updated successfully"
     *     ),
     *     @OA\Response(response=403, description="Cannot change your own status")
     * )
     */
    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $employee = User::findOrFail($id);

        $validated = $request->validate([
            'status' => 'required|in:active,inactive,suspended',
            'reason' => 'nullable|string|max:500'
        ]);

        // Prevent changing your own status
        if ($employee->id === auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot change your own status',
                'error_code' => 'SELF_STATUS_CHANGE_NOT_ALLOWED'
            ], 403);
        }

        $employee->update([
            'status' => $validated['status']
        ]);

        // Log the status change (optional, add logging logic here)
        \Log::info('Employee status changed', [
            'employee_id' => $employee->id,
            'changed_by' => auth()->id(),
            'old_status' => $employee->getOriginal('status'),
            'new_status' => $validated['status'],
            'reason' => $validated['reason'] ?? 'No reason provided'
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Employee status updated successfully',
            'data' => $employee
        ]);
    }

    /**
     * Get employee statistics
     *
     * @OA\Get(
     *     path="/hr/employees/stats",
     *     tags={"Employee Management"},
     *     summary="Get employee statistics",
     *     description="Get comprehensive employee statistics including counts by status and role",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Employee statistics retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Employee statistics retrieved successfully"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="total_employees", type="integer", example=150),
     *                 @OA\Property(property="active_employees", type="integer", example=120),
     *                 @OA\Property(property="inactive_employees", type="integer", example=20),
     *                 @OA\Property(property="suspended_employees", type="integer", example=10),
     *                 @OA\Property(property="by_role", type="object"),
     *                 @OA\Property(property="recent_hires", type="integer", example=5)
     *             )
     *         )
     *     ),
     *     @OA\Response(response=403, description="Forbidden")
     * )
     */
    public function stats(): JsonResponse
    {
        $stats = [
            'total_employees' => User::count(),
            'active_employees' => User::where('status', 'active')->count(),
            'inactive_employees' => User::where('status', 'inactive')->count(),
            'suspended_employees' => User::where('status', 'suspended')->count(),
            'by_role' => User::with('roles')
                ->get()
                ->groupBy(fn($user) => $user->roles->pluck('name')->first() ?? 'no-role')
                ->map(fn($group) => $group->count()),
            'recent_hires' => User::where('hire_date', '>=', now()->subDays(30))->count(),
        ];

        return response()->json([
            'success' => true,
            'message' => 'Employee statistics retrieved successfully',
            'data' => $stats
        ]);
    }
}
