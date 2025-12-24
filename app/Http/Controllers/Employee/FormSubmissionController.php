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
     *     summary="List available forms",
     *     description="Get all active form templates available for submission",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Forms retrieved successfully"
     *     )
     * )
     */
    public function availableForms(): JsonResponse
    {
        try {
            $forms = FormTemplate::with('fields')
                ->active()
                ->latest()
                ->get();

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
     *     summary="Submit form",
     *     description="Create a new form submission with responses",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"form_template_id", "responses"},
     *             @OA\Property(property="form_template_id", type="integer", example=1),
     *             @OA\Property(property="status", type="string", enum={"draft", "submitted"}, example="submitted"),
     *             @OA\Property(
     *                 property="responses",
     *                 type="object",
     *                 example={"1": "John Doe", "2": "john@example.com", "3": "2024-01-15"}
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Form submitted successfully"
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
