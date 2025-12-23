<?php

namespace App\Http\Controllers;

use App\Models\FormTemplate;
use App\Models\FormField;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class FormTemplateController extends Controller
{
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

            return response()->json([
                'success' => true,
                'message' => 'Form templates retrieved successfully',
                'data' => [
                    'templates' => $templates->items(),
                    'pagination' => [
                        'total' => $templates->total(),
                        'per_page' => $templates->perPage(),
                        'current_page' => $templates->currentPage(),
                        'last_page' => $templates->lastPage(),
                        'from' => $templates->firstItem(),
                        'to' => $templates->lastItem(),
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve form templates', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve form templates',
                'data' => config('app.debug') ? ['error' => $e->getMessage()] : null
            ], 500);
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

            return response()->json([
                'success' => true,
                'message' => 'Form template created successfully',
                'data' => $template->load('fields')
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create form template', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create form template',
                'data' => config('app.debug') ? ['error' => $e->getMessage()] : null
            ], 500);
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

            return response()->json([
                'success' => true,
                'message' => 'Form template retrieved successfully',
                'data' => $template
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Form template not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve form template', [
                'template_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve form template',
                'data' => config('app.debug') ? ['error' => $e->getMessage()] : null
            ], 500);
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

            return response()->json([
                'success' => true,
                'message' => 'Form template updated successfully',
                'data' => $template->load('fields')
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Form template not found'
            ], 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Failed to update form template', [
                'template_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update form template',
                'data' => config('app.debug') ? ['error' => $e->getMessage()] : null
            ], 500);
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

            // TODO: Check if template has submissions once FormSubmission model is created
            // if ($template->submissions()->count() > 0) {
            //     return response()->json([
            //         'success' => false,
            //         'message' => 'Cannot delete template with existing submissions'
            //     ], 422);
            // }

            $template->delete();

            Log::info('Form template deleted', [
                'template_id' => $id,
                'deleted_by' => auth()->id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Form template deleted successfully'
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Form template not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to delete form template', [
                'template_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete form template',
                'data' => config('app.debug') ? ['error' => $e->getMessage()] : null
            ], 500);
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

            return response()->json([
                'success' => true,
                'message' => 'Form template duplicated successfully',
                'data' => $newTemplate->load('fields')
            ], 201);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Form template not found'
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to duplicate form template', [
                'template_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to duplicate form template',
                'data' => config('app.debug') ? ['error' => $e->getMessage()] : null
            ], 500);
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

            return response()->json([
                'success' => true,
                'message' => 'Field added successfully',
                'data' => $field
            ], 201);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Form template not found'
            ], 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Failed to add field to template', [
                'template_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to add field',
                'data' => config('app.debug') ? ['error' => $e->getMessage()] : null
            ], 500);
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

            return response()->json([
                'success' => true,
                'message' => 'Field updated successfully',
                'data' => $field
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Template or field not found'
            ], 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Failed to update field', [
                'template_id' => $id,
                'field_id' => $fieldId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update field',
                'data' => config('app.debug') ? ['error' => $e->getMessage()] : null
            ], 500);
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

            return response()->json([
                'success' => true,
                'message' => 'Field removed successfully'
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Template or field not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to remove field', [
                'template_id' => $id,
                'field_id' => $fieldId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to remove field',
                'data' => config('app.debug') ? ['error' => $e->getMessage()] : null
            ], 500);
        }
    }
}
