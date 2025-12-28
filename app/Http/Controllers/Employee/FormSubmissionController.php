<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Http\Requests\FormSubmissionRequest;
use App\Http\Requests\UpdateFormSubmissionRequest;
use App\Models\FormTemplate;
use App\Models\FormSubmission;
use App\Models\SubmissionResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FormSubmissionController extends Controller
{
    use ApiResponse;
    /**
     * Get available form templates for employees
     *
     * @OA\Get(
     *     path="/employee/forms",
     *     tags={"Form Submissions"},
     *     summary="List available forms for current user",
     *     description="Get all active form templates available for submission. Includes main templates and user-specific templates (KYE/KYA) based on user role: Employees see main templates + their assigned KYE templates, Admin/HR see all templates including KYA evaluation forms.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Forms retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Available forms retrieved successfully"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="title", type="string", example="Employee Onboarding"),
     *                     @OA\Property(property="description", type="string", example="New employee information form"),
     *                     @OA\Property(property="status", type="string", example="active"),
     *                     @OA\Property(property="template_type", type="string", enum={"main", "kye", "kya"}, example="main"),
     *                     @OA\Property(property="parent_template_id", type="integer", nullable=true, example=null),
     *                     @OA\Property(property="assigned_to", type="integer", nullable=true, example=null),
     *                     @OA\Property(
     *                         property="fields",
     *                         type="array",
     *                         @OA\Items(
     *                             type="object",
     *                             @OA\Property(property="id", type="integer", example=1),
     *                             @OA\Property(property="field_type", type="string", example="text"),
     *                             @OA\Property(property="label", type="string", example="Full Name"),
     *                             @OA\Property(property="placeholder", type="string", example="Enter your name"),
     *                             @OA\Property(property="is_required", type="boolean", example=true),
     *                             @OA\Property(property="order", type="integer", example=1),
     *                             @OA\Property(property="options", type="array", nullable=true, @OA\Items(type="string"), example="[""Option 1"", ""Option 2""]"),
     *                             @OA\Property(property="validation_rules", type="object", nullable=true, example="{""min"": 18, ""max"": 100}")
     *                         )
     *                     )
     *                 )
     *             )
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
    public function availableForms(): JsonResponse
    {
        try {
            $user = auth()->user();
            
            // Get main active templates
            $mainForms = FormTemplate::with('fields')
                ->active()
                ->mainTemplates()
                ->latest()
                ->get();

            // Get user-specific templates based on role and template type
            $userSpecificForms = collect();
            
            if ($user->hasRole(['admin', 'hr'])) {
                // Admin/HR can see all user-specific templates (both KYE and KYA)
                $userSpecificForms = FormTemplate::with('fields')
                    ->active()
                    ->userSpecific()
                    ->latest()
                    ->get();
            } else {
                // Regular employees can only see KYE templates assigned to them
                $userSpecificForms = FormTemplate::with('fields')
                    ->active()
                    ->where('template_type', FormTemplate::TEMPLATE_TYPE_KYE)
                    ->assignedTo($user->id)
                    ->latest()
                    ->get();
            }

            // Combine both types of forms
            $forms = $mainForms->merge($userSpecificForms);

            return $this->successResponse('Available forms retrieved successfully', $forms);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve available forms', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return $this->serverErrorResponse(
                'Failed to retrieve available forms',
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Get my submissions
     *
     * @OA\Get(
     *     path="/employee/submissions",
     *     tags={"Form Submissions"},
     *     summary="My submissions",
     *     description="Get all my form submissions with pagination and filters",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         description="Page number",
     *         required=false,
     *         @OA\Schema(type="integer", default=1)
     *     ),
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
     *         @OA\Schema(type="string", enum={"draft", "submitted", "approved", "rejected"})
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Submissions retrieved successfully"
     *     )
     * )
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = $request->get('per_page', 15);
            $page = $request->get('page', 1);
            $status = $request->get('status');

            $submissions = FormSubmission::with(['template:id,title', 'responses.field'])
                ->byUser(auth()->id())
                ->when($status, function ($query, $status) {
                    return $query->where('status', $status);
                })
                ->latest()
                ->paginate($perPage, ['*'], 'page', $page);

            return $this->successResponse('Submissions retrieved successfully', [
                'submissions' => $submissions->items(),
                'pagination' => [
                    'total' => $submissions->total(),
                    'per_page' => $submissions->perPage(),
                    'current_page' => $submissions->currentPage(),
                    'last_page' => $submissions->lastPage(),
                    'from' => $submissions->firstItem(),
                    'to' => $submissions->lastItem(),
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve submissions', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return $this->serverErrorResponse(
                'Failed to retrieve submissions',
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Submit a form
     *
     * @OA\Post(
     *     path="/employee/submissions",
     *     tags={"Form Submissions"},
     *     summary="Submit form with responses",
     *     description="Create a new form submission with responses. Validates user access to templates: KYE templates can only be submitted by the assigned user, KYA templates can only be submitted by Admin/HR users.",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"form_template_id", "responses"},
     *             @OA\Property(property="form_template_id", type="integer", description="ID of the form template to submit", example=1),
     *             @OA\Property(property="status", type="string", enum={"draft", "submitted"}, description="Submission status", example="submitted"),
     *             @OA\Property(
     *                 property="responses",
     *                 type="object",
     *                 description="Object containing field_id => response_value pairs",
     *                 example={"1": "John Doe", "2": "john@example.com", "3": "2024-01-15"}
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Form submitted successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Form submitted successfully"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="form_template_id", type="integer", example=1),
     *                 @OA\Property(property="user_id", type="integer", example=3),
     *                 @OA\Property(property="status", type="string", example="submitted"),
     *                 @OA\Property(property="submitted_at", type="string", format="date-time", example="2024-01-15T10:30:00Z"),
     *                 @OA\Property(
     *                     property="template",
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="title", type="string", example="Employee Assessment")
     *                 ),
     *                 @OA\Property(
     *                     property="responses",
     *                     type="array",
     *                     @OA\Items(
     *                         type="object",
     *                         @OA\Property(property="id", type="integer", example=1),
     *                         @OA\Property(property="form_field_id", type="integer", example=1),
     *                         @OA\Property(property="response_value", type="string", example="John Doe"),
     *                         @OA\Property(
     *                             property="field",
     *                             type="object",
     *                             @OA\Property(property="label", type="string", example="Full Name"),
     *                             @OA\Property(property="field_type", type="string", example="text")
     *                         )
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="Forbidden - User does not have access to this template",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="You do not have permission to submit this form")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation failed",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Validation failed"),
     *             @OA\Property(property="errors", type="object", example={"responses.1": {"The responses.1 field is required"}})
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
    public function store(FormSubmissionRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $template = $request->getFormTemplate();

            DB::beginTransaction();

            $submission = FormSubmission::create([
                'form_template_id' => $validated['form_template_id'],
                'user_id' => auth()->id(),
                'status' => $validated['status'] ?? 'draft',
                'submitted_at' => ($validated['status'] ?? 'draft') === 'submitted' ? now() : null,
            ]);

            // Save responses
            foreach ($validated['responses'] as $fieldId => $value) {
                SubmissionResponse::create([
                    'form_submission_id' => $submission->id,
                    'form_field_id' => $fieldId,
                    'response_value' => $value,
                ]);
            }

            DB::commit();

            Log::info('Form submission created', [
                'submission_id' => $submission->id,
                'user_id' => auth()->id(),
                'template_id' => $validated['form_template_id']
            ]);

            return $this->successResponse(
                'Form submitted successfully',
                $submission->load(['template', 'responses.field']),
                201
            );

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create submission', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return $this->serverErrorResponse(
                'Failed to create submission',
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Get a specific submission
     *
     * @OA\Get(
     *     path="/employee/submissions/{id}",
     *     tags={"Form Submissions"},
     *     summary="Get submission details",
     *     description="Get detailed information about a specific submission",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Submission ID",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Submission retrieved successfully"
     *     ),
     *     @OA\Response(response=403, description="Not your submission"),
     *     @OA\Response(response=404, description="Submission not found")
     * )
     */
    public function show(string $id): JsonResponse
    {
        try {
            $submission = FormSubmission::with(['template.fields', 'responses.field', 'reviewer:id,name'])
                ->findOrFail($id);

            // Check ownership
            if ($submission->user_id !== auth()->id()) {
                return $this->forbiddenResponse('You do not have permission to view this submission');
            }

            return $this->successResponse('Submission retrieved successfully', $submission);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->notFoundResponse('Submission not found');
        } catch (\Exception $e) {
            Log::error('Failed to retrieve submission', [
                'submission_id' => $id,
                'error' => $e->getMessage()
            ]);

            return $this->serverErrorResponse(
                'Failed to retrieve submission',
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Update a draft submission
     *
     * @OA\Put(
     *     path="/employee/submissions/{id}",
     *     tags={"Form Submissions"},
     *     summary="Update draft submission",
     *     description="Update a submission that is still in draft status",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Submission ID",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\RequestBody(
     *         required=false,
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="string", enum={"draft", "submitted"}),
     *             @OA\Property(
     *                 property="responses",
     *                 type="object",
     *                 example={"1": "Updated Value", "2": "updated@example.com"}
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Submission updated successfully"
     *     ),
     *     @OA\Response(response=422, description="Cannot update submitted forms")
     * )
     */
    public function update(UpdateFormSubmissionRequest $request, string $id): JsonResponse
    {
        try {
            $submission = $request->getSubmission();

            if (!$submission) {
                return $this->notFoundResponse('Submission not found');
            }

            // Check ownership
            if ($submission->user_id !== auth()->id()) {
                return $this->forbiddenResponse('You do not have permission to update this submission');
            }

            // Check if editable
            if (!$submission->isEditable()) {
                return $this->validationErrorResponse(
                    'Cannot update submission that has been submitted',
                    []
                );
            }

            $validated = $request->validated();

            DB::beginTransaction();

            // Update submission status
            if (isset($validated['status'])) {
                $submission->update([
                    'status' => $validated['status'],
                    'submitted_at' => $validated['status'] === 'submitted' ? now() : null,
                ]);
            }

            // Update responses
            if (isset($validated['responses'])) {
                foreach ($validated['responses'] as $fieldId => $value) {
                    $response = SubmissionResponse::where('form_submission_id', $submission->id)
                        ->where('form_field_id', $fieldId)
                        ->first();

                    if ($response) {
                        $response->update(['response_value' => $value]);
                    } else {
                        SubmissionResponse::create([
                            'form_submission_id' => $submission->id,
                            'form_field_id' => $fieldId,
                            'response_value' => $value,
                        ]);
                    }
                }
            }

            DB::commit();

            Log::info('Submission updated', [
                'submission_id' => $submission->id,
                'user_id' => auth()->id()
            ]);

            return $this->successResponse(
                'Submission updated successfully',
                $submission->fresh()->load(['template', 'responses.field'])
            );

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update submission', [
                'submission_id' => $id,
                'error' => $e->getMessage()
            ]);

            return $this->serverErrorResponse(
                'Failed to update submission',
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Delete a draft submission
     *
     * @OA\Delete(
     *     path="/employee/submissions/{id}",
     *     tags={"Form Submissions"},
     *     summary="Delete draft submission",
     *     description="Delete a submission that is still in draft status",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Submission ID",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Submission deleted successfully"
     *     ),
     *     @OA\Response(response=422, description="Cannot delete submitted forms")
     * )
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $submission = FormSubmission::findOrFail($id);

            // Check ownership
            if ($submission->user_id !== auth()->id()) {
                return $this->forbiddenResponse('You do not have permission to delete this submission');
            }

            // Check if deletable
            if (!$submission->isDeletable()) {
                return $this->validationErrorResponse(
                    'Cannot delete submission that has been submitted',
                    []
                );
            }

            $submission->delete();

            Log::info('Submission deleted', [
                'submission_id' => $id,
                'user_id' => auth()->id()
            ]);

            return $this->successResponse('Submission deleted successfully');

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->notFoundResponse('Submission not found');
        } catch (\Exception $e) {
            Log::error('Failed to delete submission', [
                'submission_id' => $id,
                'error' => $e->getMessage()
            ]);

            return $this->serverErrorResponse(
                'Failed to delete submission',
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }
}
