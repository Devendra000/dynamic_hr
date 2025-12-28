<?php

namespace App\Http\Controllers;

use App\Traits\ApiResponse;
use App\Models\FormTemplate;
use App\Models\FormField;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class FormTemplateController extends Controller
{
    use ApiResponse;
    /**
     * Get all form templates
     *
     * @OA\Get(
     *     path="/admin/form-templates",
     *     tags={"Form Template Management"},
     *     summary="List all form templates",
     *     description="Get paginated list of form templates with their fields",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="per_page",
     *         in="query",
     *         description="Items per page",
     *         required=false,
     *         @OA\Schema(type="integer", default=15)
     *     ),
     *     @OA\Parameter(
     *         name="status",
     *         in="query",
     *         description="Filter by status",
     *         required=false,
     *         @OA\Schema(type="string", enum={"active", "inactive", "draft"})
     *     ),
     *     @OA\Parameter(
     *         name="search",
     *         in="query",
     *         description="Search by title",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Templates retrieved successfully"
     *     )
     * )
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = $request->get('per_page', 15);
            $status = $request->get('status');
            $search = $request->get('search');

            $templates = FormTemplate::with(['fields', 'creator:id,name,email'])
                ->when($status, function ($query, $status) {
                    return $query->where('status', $status);
                })
                ->when($search, function ($query, $search) {
                    return $query->where('title', 'like', "%{$search}%");
                })
                ->latest()
                ->paginate($perPage);

            return $this->successResponse('Form templates retrieved successfully', [
                'templates' => $templates->items(),
                'pagination' => [
                    'total' => $templates->total(),
                    'per_page' => $templates->perPage(),
                    'current_page' => $templates->currentPage(),
                    'last_page' => $templates->lastPage(),
                    'from' => $templates->firstItem(),
                    'to' => $templates->lastItem(),
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve form templates', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return $this->serverErrorResponse(
                'Failed to retrieve form templates',
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Create a new form template
     *
     * @OA\Post(
     *     path="/admin/form-templates",
     *     tags={"Form Template Management"},
     *     summary="Create new form template",
     *     description="Create a new form template with optional fields",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"title"},
     *             @OA\Property(property="title", type="string", example="Employee Feedback Form"),
     *             @OA\Property(property="description", type="string", example="Annual employee feedback survey"),
     *             @OA\Property(property="status", type="string", enum={"active", "inactive", "draft"}, example="draft"),
     *             @OA\Property(
     *                 property="fields",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     required={"field_type", "label"},
     *                     @OA\Property(
     *                         property="field_type",
     *                         type="string",
     *                         enum={"text", "textarea", "number", "email", "date", "dropdown", "checkbox", "radio", "file"},
     *                         example="text"
     *                     ),
     *                     @OA\Property(property="label", type="string", example="Your Name"),
     *                     @OA\Property(property="placeholder", type="string", example="Enter your name"),
     *                     @OA\Property(
     *                         property="options",
     *                         type="array",
     *                         description="Required for dropdown, checkbox, radio fields",
     *                         @OA\Items(type="string"),
     *                         example={"Option 1", "Option 2", "Option 3"}
     *                     ),
     *                     @OA\Property(
     *                         property="validation_rules",
     *                         type="object",
     *                         example={"min": 3, "max": 100}
     *                     ),
     *                     @OA\Property(property="is_required", type="boolean", example=true),
     *                     @OA\Property(property="order", type="integer", example=1)
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Template created successfully"
     *     )
     * )
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'description' => 'nullable|string',
                'status' => ['nullable', Rule::in(FormTemplate::getStatuses())],
                'fields' => 'nullable|array',
                'fields.*.field_type' => ['required', Rule::in(FormTemplate::getFieldTypes())],
                'fields.*.label' => 'required|string|max:255',
                'fields.*.placeholder' => 'nullable|string|max:255',
                'fields.*.options' => 'nullable|array',
                'fields.*.validation_rules' => 'nullable|array',
                'fields.*.is_required' => 'nullable|boolean',
                'fields.*.order' => 'nullable|integer',
            ]);

            DB::beginTransaction();

            $template = FormTemplate::create([
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'status' => $validated['status'] ?? 'draft',
                'created_by' => auth()->id()
            ]);

            // Add fields if provided
            if (isset($validated['fields'])) {
                foreach ($validated['fields'] as $fieldData) {
                    $template->fields()->create($fieldData);
                }
            }

            DB::commit();

            Log::info('Form template created', [
                'template_id' => $template->id,
                'created_by' => auth()->id()
            ]);

            return $this->successResponse('Form template created successfully', $template->load('fields'), 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return $this->validationErrorResponse('Validation failed', $e->errors());
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create form template', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return $this->serverErrorResponse(
                'Failed to create form template',
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Create a user-specific template from a main template
     *
     * @OA\Post(
     *     path="/admin/form-templates/{id}/assign-user",
     *     tags={"Form Template Management"},
     *     summary="Create user-specific template (KYE/KYA)",
     *     description="Create a KYE (Know Your Employee) or KYA (Know Your Associate) template for a specific user based on a main template. KYE templates are for self-assessment, KYA templates are for evaluations by HR/Admin.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Main template ID to use as base",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"user_id", "template_type"},
     *             @OA\Property(property="user_id", type="integer", description="User ID to assign the template to", example=3),
     *             @OA\Property(
     *                 property="template_type",
     *                 type="string",
     *                 enum={"kye", "kya"},
     *                 description="Type of user-specific template: 'kye' for self-assessment, 'kya' for HR evaluation",
     *                 example="kye"
     *             ),
     *             @OA\Property(property="title", type="string", description="Custom title for the user-specific template", example="Know Your Employee - Self Assessment"),
     *             @OA\Property(property="description", type="string", description="Custom description", example="Personal development and performance review")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="User-specific template created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="User-specific template created successfully"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=2),
     *                 @OA\Property(property="title", type="string", example="Know Your Employee - Self Assessment"),
     *                 @OA\Property(property="description", type="string", example="Personal development and performance review"),
     *                 @OA\Property(property="status", type="string", example="active"),
     *                 @OA\Property(property="template_type", type="string", example="kye"),
     *                 @OA\Property(property="parent_template_id", type="integer", example=1),
     *                 @OA\Property(property="assigned_to", type="integer", example=3),
     *                 @OA\Property(property="created_by", type="integer", example=1),
     *                 @OA\Property(
     *                     property="fields",
     *                     type="array",
     *                     @OA\Items(
     *                         type="object",
     *                         @OA\Property(property="id", type="integer", example=5),
     *                         @OA\Property(property="field_type", type="string", example="text"),
     *                         @OA\Property(property="label", type="string", example="Full Name"),
     *                         @OA\Property(property="is_required", type="boolean", example=true),
     *                         @OA\Property(property="order", type="integer", example=1)
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="Forbidden - Only Admin and HR can create KYA templates",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Only Admin and HR users can create KYA templates")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad Request - Template already exists or invalid main template",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="User-specific template already exists for this user and type")
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Unauthenticated")
     *         )
     *     )
     * )
     */
    public function assignToUser(Request $request, $templateId): JsonResponse
    {
        try {
            $user = auth()->user();
            $validated = $request->validate([
                'user_id' => 'required|exists:users,id',
                'template_type' => ['required', Rule::in([FormTemplate::TEMPLATE_TYPE_KYE, FormTemplate::TEMPLATE_TYPE_KYA])],
                'title' => 'nullable|string|max:255',
                'description' => 'nullable|string',
            ]);

            // Role-based validation for template types
            if ($validated['template_type'] === FormTemplate::TEMPLATE_TYPE_KYA && !$user->hasRole(['admin', 'hr'])) {
                return $this->forbiddenResponse('Only Admin and HR users can create KYA templates');
            }

            // Check if main template exists
            try {
                $mainTemplate = FormTemplate::findOrFail($templateId);
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
                return $this->notFoundResponse('Main template not found');
            }
            if (!$mainTemplate->isMainTemplate()) {
                return $this->errorResponse('Only main templates can be assigned to users', null, 400);
            }

            // Check if user-specific template already exists for this user and type
            $existingTemplate = FormTemplate::where('parent_template_id', $templateId)
                ->where('assigned_to', $validated['user_id'])
                ->where('template_type', $validated['template_type'])
                ->first();

            if ($existingTemplate) {
                return $this->errorResponse('User-specific template already exists for this user and type', null, 400);
            }

            DB::beginTransaction();

            try {
                // Create user-specific template
                $userTemplate = FormTemplate::create([
                    'title' => $validated['title'] ?? $mainTemplate->title . ' (' . strtoupper($validated['template_type']) . ')',
                    'description' => $validated['description'] ?? $mainTemplate->description,
                    'status' => 'active',
                    'template_type' => $validated['template_type'],
                    'parent_template_id' => $mainTemplate->id,
                    'assigned_to' => $validated['user_id'],
                    'created_by' => auth()->id()
                ]);

                // Copy fields from main template
                foreach ($mainTemplate->fields as $field) {
                    $userTemplate->fields()->create([
                        'field_type' => $field->field_type,
                        'label' => $field->label,
                        'placeholder' => $field->placeholder,
                        'options' => $field->options,
                        'validation_rules' => $field->validation_rules,
                        'is_required' => $field->is_required,
                        'order' => $field->order,
                    ]);
                }

                DB::commit();

                Log::info('User-specific template created', [
                    'template_id' => $userTemplate->id,
                    'parent_template_id' => $mainTemplate->id,
                    'assigned_to' => $validated['user_id'],
                    'template_type' => $validated['template_type']
                ]);

                return $this->successResponse('User-specific template created successfully', $userTemplate->load('fields'), 201);

            } catch (\Illuminate\Database\QueryException $e) {
                DB::rollBack();
                Log::error('Database error while creating user-specific template', [
                    'error' => $e->getMessage(),
                    'template_id' => $templateId,
                    'user_id' => $validated['user_id']
                ]);

                if (str_contains($e->getMessage(), 'duplicate key') || str_contains($e->getMessage(), 'unique constraint')) {
                    return $this->errorResponse('A template with similar configuration already exists', null, 400);
                }

                return $this->serverErrorResponse('Database error occurred while creating template');
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Unexpected error while creating user-specific template', [
                    'error' => $e->getMessage(),
                    'template_id' => $templateId,
                    'user_id' => $validated['user_id']
                ]);

                return $this->serverErrorResponse('Unexpected error occurred while creating template');
            }

        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->validationErrorResponse('Validation failed', $e->errors());
        } catch (\Exception $e) {
            Log::error('Failed to create user-specific template', [
                'error' => $e->getMessage(),
                'template_id' => $templateId,
                'user_id' => auth()->id()
            ]);

            return $this->serverErrorResponse(
                'Failed to create user-specific template',
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Get a specific form template
     *
     * @OA\Get(
     *     path="/admin/form-templates/{id}",
     *     tags={"Form Template Management"},
     *     summary="Get form template details",
     *     description="Get detailed information about a specific form template with all fields",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Template ID",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Template retrieved successfully"
     *     ),
     *     @OA\Response(response=404, description="Template not found")
     * )
     */
    public function show(string $id): JsonResponse
    {
        try {
            $template = FormTemplate::with(['fields' => function ($query) {
                $query->orderBy('order');
            }, 'creator:id,name,email'])
                ->findOrFail($id);

            return $this->successResponse('Form template retrieved successfully', $template);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->notFoundResponse('Form template not found');
        } catch (\Exception $e) {
            Log::error('Failed to retrieve form template', [
                'template_id' => $id,
                'error' => $e->getMessage()
            ]);

            return $this->serverErrorResponse(
                'Failed to retrieve form template',
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Update a form template
     *
     * @OA\Put(
     *     path="/admin/form-templates/{id}",
     *     tags={"Form Template Management"},
     *     summary="Update form template",
     *     description="Update form template basic information (title, description, status)",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Template ID",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\RequestBody(
     *         required=false,
     *         @OA\JsonContent(
     *             @OA\Property(property="title", type="string", example="Updated Form Title"),
     *             @OA\Property(property="description", type="string", example="Updated description"),
     *             @OA\Property(property="status", type="string", enum={"active", "inactive", "draft"})
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Template updated successfully"
     *     )
     * )
     */
    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $template = FormTemplate::findOrFail($id);

            $validated = $request->validate([
                'title' => 'sometimes|string|max:255',
                'description' => 'nullable|string',
                'status' => ['sometimes', Rule::in(FormTemplate::getStatuses())],
            ]);

            $template->update($validated);

            Log::info('Form template updated', [
                'template_id' => $template->id,
                'updated_by' => auth()->id()
            ]);

            return $this->successResponse('Form template updated successfully', $template->load('fields'));

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->notFoundResponse('Form template not found');
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->validationErrorResponse('Validation failed', $e->errors());
        } catch (\Exception $e) {
            Log::error('Failed to update form template', [
                'template_id' => $id,
                'error' => $e->getMessage()
            ]);

            return $this->serverErrorResponse(
                'Failed to update form template',
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Delete a form template
     *
     * @OA\Delete(
     *     path="/admin/form-templates/{id}",
     *     tags={"Form Template Management"},
     *     summary="Delete form template",
     *     description="Soft delete a form template (cannot delete if it has submissions)",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Template ID",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Template deleted successfully"
     *     ),
     *     @OA\Response(response=422, description="Template has submissions")
     * )
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $template = FormTemplate::findOrFail($id);

            $template->delete();

            Log::info('Form template deleted', [
                'template_id' => $id,
                'deleted_by' => auth()->id()
            ]);

            return $this->successResponse('Form template deleted successfully');

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->notFoundResponse('Form template not found');
        } catch (\Exception $e) {
            Log::error('Failed to delete form template', [
                'template_id' => $id,
                'error' => $e->getMessage()
            ]);

            return $this->serverErrorResponse(
                'Failed to delete form template',
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Duplicate a form template
     *
     * @OA\Post(
     *     path="/admin/form-templates/{id}/duplicate",
     *     tags={"Form Template Management"},
     *     summary="Duplicate form template",
     *     description="Create a copy of an existing form template with all its fields",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Template ID to duplicate",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Template duplicated successfully"
     *     )
     * )
     */
    public function duplicate(string $id): JsonResponse
    {
        try {
            $originalTemplate = FormTemplate::with('fields')->findOrFail($id);

            DB::beginTransaction();

            $newTemplate = FormTemplate::create([
                'title' => $originalTemplate->title . ' (Copy)',
                'description' => $originalTemplate->description,
                'status' => 'draft',
                'created_by' => auth()->id()
            ]);

            // Duplicate all fields
            foreach ($originalTemplate->fields as $field) {
                $newTemplate->fields()->create([
                    'field_type' => $field->field_type,
                    'label' => $field->label,
                    'placeholder' => $field->placeholder,
                    'options' => $field->options,
                    'validation_rules' => $field->validation_rules,
                    'is_required' => $field->is_required,
                    'order' => $field->order,
                ]);
            }

            DB::commit();

            Log::info('Form template duplicated', [
                'original_id' => $id,
                'new_id' => $newTemplate->id,
                'created_by' => auth()->id()
            ]);

            return $this->successResponse('Form template duplicated successfully', $newTemplate->load('fields'), 201);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();
            return $this->notFoundResponse('Form template not found');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to duplicate form template', [
                'template_id' => $id,
                'error' => $e->getMessage()
            ]);

            return $this->serverErrorResponse(
                'Failed to duplicate form template',
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Add field to template
     *
     * @OA\Post(
     *     path="/admin/form-templates/{id}/fields",
     *     tags={"Form Template Management"},
     *     summary="Add field to template",
     *     description="Add a new field to an existing form template",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Template ID",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"field_type", "label"},
     *             @OA\Property(
     *                 property="field_type",
     *                 type="string",
     *                 enum={"text", "textarea", "number", "email", "date", "dropdown", "checkbox", "radio", "file"},
     *                 example="email"
     *             ),
     *             @OA\Property(property="label", type="string", example="Email Address"),
     *             @OA\Property(property="placeholder", type="string", example="Enter your email"),
     *             @OA\Property(
     *                 property="options",
     *                 type="array",
     *                 description="Required for dropdown, checkbox, radio fields",
     *                 @OA\Items(type="string"),
     *                 example={"Yes", "No", "Maybe"}
     *             ),
     *             @OA\Property(
     *                 property="validation_rules",
     *                 type="object",
     *                 description="Custom validation rules",
     *                 example={"email": true, "max": 255}
     *             ),
     *             @OA\Property(property="is_required", type="boolean", example=true),
     *             @OA\Property(property="order", type="integer", example=2)
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Field added successfully"
     *     )
     * )
     */
    public function addField(Request $request, string $id): JsonResponse
    {
        try {
            $template = FormTemplate::findOrFail($id);

            $validated = $request->validate([
                'field_type' => ['required', Rule::in(FormTemplate::getFieldTypes())],
                'label' => 'required|string|max:255',
                'placeholder' => 'nullable|string|max:255',
                'options' => 'nullable|array',
                'validation_rules' => 'nullable|array',
                'is_required' => 'nullable|boolean',
                'order' => 'nullable|integer',
            ]);

            // Auto-set order if not provided
            if (!isset($validated['order'])) {
                $validated['order'] = $template->fields()->max('order') + 1;
            }

            $field = $template->fields()->create($validated);

            Log::info('Field added to template', [
                'template_id' => $template->id,
                'field_id' => $field->id,
                'added_by' => auth()->id()
            ]);

            return $this->successResponse('Field added successfully', $field, 201);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->notFoundResponse('Form template not found');
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->validationErrorResponse('Validation failed', $e->errors());
        } catch (\Exception $e) {
            Log::error('Failed to add field to template', [
                'template_id' => $id,
                'error' => $e->getMessage()
            ]);

            return $this->serverErrorResponse(
                'Failed to add field',
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Update field in template
     *
     * @OA\Put(
     *     path="/admin/form-templates/{id}/fields/{fieldId}",
     *     tags={"Form Template Management"},
     *     summary="Update template field",
     *     description="Update an existing field in a form template",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Template ID",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="fieldId",
     *         in="path",
     *         description="Field ID",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\RequestBody(
     *         required=false,
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 property="field_type",
     *                 type="string",
     *                 enum={"text", "textarea", "number", "email", "date", "dropdown", "checkbox", "radio", "file"}
     *             ),
     *             @OA\Property(property="label", type="string", example="Updated Label"),
     *             @OA\Property(property="placeholder", type="string"),
     *             @OA\Property(
     *                 property="options",
     *                 type="array",
     *                 @OA\Items(type="string")
     *             ),
     *             @OA\Property(property="validation_rules", type="object"),
     *             @OA\Property(property="is_required", type="boolean"),
     *             @OA\Property(property="order", type="integer")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Field updated successfully"
     *     )
     * )
     */
    public function updateField(Request $request, string $id, string $fieldId): JsonResponse
    {
        try {
            $template = FormTemplate::findOrFail($id);
            $field = FormField::where('form_template_id', $id)
                ->where('id', $fieldId)
                ->firstOrFail();

            $validated = $request->validate([
                'field_type' => ['sometimes', Rule::in(FormTemplate::getFieldTypes())],
                'label' => 'sometimes|string|max:255',
                'placeholder' => 'nullable|string|max:255',
                'options' => 'nullable|array',
                'validation_rules' => 'nullable|array',
                'is_required' => 'nullable|boolean',
                'order' => 'nullable|integer',
            ]);

            $field->update($validated);

            Log::info('Field updated', [
                'template_id' => $id,
                'field_id' => $fieldId,
                'updated_by' => auth()->id()
            ]);

            return $this->successResponse('Field updated successfully', $field);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->notFoundResponse('Template or field not found');
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->validationErrorResponse('Validation failed', $e->errors());
        } catch (\Exception $e) {
            Log::error('Failed to update field', [
                'template_id' => $id,
                'field_id' => $fieldId,
                'error' => $e->getMessage()
            ]);

            return $this->serverErrorResponse(
                'Failed to update field',
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Remove field from template
     *
     * @OA\Delete(
     *     path="/admin/form-templates/{id}/fields/{fieldId}",
     *     tags={"Form Template Management"},
     *     summary="Remove field from template",
     *     description="Delete a field from a form template",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Template ID",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="fieldId",
     *         in="path",
     *         description="Field ID",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Field removed successfully"
     *     )
     * )
     */
    public function removeField(string $id, string $fieldId): JsonResponse
    {
        try {
            $template = FormTemplate::findOrFail($id);
            $field = FormField::where('form_template_id', $id)
                ->where('id', $fieldId)
                ->firstOrFail();

            $field->delete();

            Log::info('Field removed from template', [
                'template_id' => $id,
                'field_id' => $fieldId,
                'removed_by' => auth()->id()
            ]);

            return $this->successResponse('Field removed successfully');

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->notFoundResponse('Template or field not found');
        } catch (\Exception $e) {
            Log::error('Failed to remove field', [
                'template_id' => $id,
                'field_id' => $fieldId,
                'error' => $e->getMessage()
            ]);

            return $this->serverErrorResponse(
                'Failed to remove field',
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }
}
