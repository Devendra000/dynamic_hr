<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Models\FormSubmission;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FormSubmissionAdminController extends Controller
{
    use ApiResponse;
    /**
     * Get all submissions
     *
     * @OA\Get(
     *     path="/admin/submissions",
     *     tags={"Form Submissions"},
     *     summary="Get all submissions",
     *     description="Get all form submissions with pagination and filters (Admin/HR only)",
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
     *     @OA\Parameter(
     *         name="form_template_id",
     *         in="query",
     *         description="Filter by template ID",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="user_id",
     *         in="query",
     *         description="Filter by user ID",
     *         required=false,
     *         @OA\Schema(type="integer")
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
            $formTemplateId = $request->get('form_template_id');
            $userId = $request->get('user_id');

            $submissions = FormSubmission::with(['template:id,title', 'user:id,name,email', 'reviewer:id,name'])
                ->when($status, function ($query, $status) {
                    return $query->where('status', $status);
                })
                ->when($formTemplateId, function ($query, $formTemplateId) {
                    return $query->where('form_template_id', $formTemplateId);
                })
                ->when($userId, function ($query, $userId) {
                    return $query->where('user_id', $userId);
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
                'admin_id' => auth()->id()
            ]);

            return $this->serverErrorResponse(
                'Failed to retrieve submissions',
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Get submission details
     *
     * @OA\Get(
     *     path="/admin/submissions/{id}",
     *     tags={"Form Submissions"},
     *     summary="Get submission details",
     *     description="Get detailed information about a specific submission (Admin/HR only)",
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
     *     @OA\Response(response=404, description="Submission not found")
     * )
     */
    public function show(string $id): JsonResponse
    {
        try {
            $submission = FormSubmission::with([
                'template.fields',
                'responses.field',
                'user:id,name,email,phone,department,position',
                'reviewer:id,name'
            ])->findOrFail($id);

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
     * Update submission status
     *
     * @OA\Put(
     *     path="/admin/submissions/{id}/status",
     *     tags={"Form Submissions"},
     *     summary="Update submission status",
     *     description="Approve or reject a form submission (Admin/HR only)",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Submission ID",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"status"},
     *             @OA\Property(property="status", type="string", enum={"approved", "rejected"}, example="approved"),
     *             @OA\Property(property="comments", type="string", example="All information looks good")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Status updated successfully"
     *     )
     * )
     */
    public function updateStatus(Request $request, string $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'status' => 'required|in:approved,rejected',
                'comments' => 'nullable|string|max:1000',
            ]);

            $submission = FormSubmission::findOrFail($id);

            // Can only review submitted forms
            if ($submission->status !== FormSubmission::STATUS_SUBMITTED) {
                return $this->validationErrorResponse(
                    'Can only review submissions that are in submitted status',
                    []
                );
            }

            DB::beginTransaction();

            $submission->update([
                'status' => $validated['status'],
                'reviewed_at' => now(),
                'reviewed_by' => auth()->id(),
                'comments' => $validated['comments'] ?? $submission->comments,
            ]);

            DB::commit();

            Log::info('Submission status updated', [
                'submission_id' => $id,
                'status' => $validated['status'],
                'reviewer_id' => auth()->id()
            ]);

            return $this->successResponse(
                'Submission status updated successfully',
                $submission->load(['template', 'user:id,name,email', 'reviewer:id,name'])
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();
            return $this->notFoundResponse('Submission not found');
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return $this->validationErrorResponse('Validation failed', $e->errors());
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update submission status', [
                'submission_id' => $id,
                'error' => $e->getMessage()
            ]);

            return $this->serverErrorResponse(
                'Failed to update submission status',
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Add comment to submission
     *
     * @OA\Post(
     *     path="/admin/submissions/{id}/comments",
     *     tags={"Form Submissions"},
     *     summary="Add comment",
     *     description="Add or update comments on a submission (Admin/HR only)",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Submission ID",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"comments"},
     *             @OA\Property(property="comments", type="string", example="Please provide additional information")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Comment added successfully"
     *     )
     * )
     */
    public function addComment(Request $request, string $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'comments' => 'required|string|max:1000',
            ]);

            $submission = FormSubmission::findOrFail($id);

            $submission->update([
                'comments' => $validated['comments'],
            ]);

            Log::info('Comment added to submission', [
                'submission_id' => $id,
                'admin_id' => auth()->id()
            ]);

            return $this->successResponse(
                'Comment added successfully',
                $submission->load(['template', 'user:id,name,email'])
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->notFoundResponse('Submission not found');
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->validationErrorResponse('Validation failed', $e->errors());
        } catch (\Exception $e) {
            Log::error('Failed to add comment', [
                'submission_id' => $id,
                'error' => $e->getMessage()
            ]);

            return $this->serverErrorResponse(
                'Failed to add comment',
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Get submission statistics
     *
     * @OA\Get(
     *     path="/admin/submissions/stats",
     *     tags={"Form Submissions"},
     *     summary="Get statistics",
     *     description="Get submission statistics and analytics (Admin/HR only)",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="form_template_id",
     *         in="query",
     *         description="Filter by template ID",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Statistics retrieved successfully"
     *     )
     * )
     */
    public function stats(Request $request): JsonResponse
    {
        try {
            $formTemplateId = $request->get('form_template_id');

            $query = FormSubmission::query();
            
            if ($formTemplateId) {
                $query->where('form_template_id', $formTemplateId);
            }

            $stats = [
                'total' => (clone $query)->count(),
                'draft' => (clone $query)->draft()->count(),
                'submitted' => (clone $query)->submitted()->count(),
                'approved' => (clone $query)->approved()->count(),
                'rejected' => (clone $query)->rejected()->count(),
                'pending_review' => (clone $query)->submitted()->count(),
            ];

            // Recent submissions
            $recentSubmissions = (clone $query)
                ->with(['template:id,title', 'user:id,name,email'])
                ->latest()
                ->take(10)
                ->get();

            return $this->successResponse('Statistics retrieved successfully', [
                'stats' => $stats,
                'recent_submissions' => $recentSubmissions,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve statistics', [
                'error' => $e->getMessage()
            ]);

            return $this->serverErrorResponse(
                'Failed to retrieve statistics',
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }
}
