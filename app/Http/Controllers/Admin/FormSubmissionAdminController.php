<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FormSubmission;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FormSubmissionAdminController extends Controller
{
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
                ->paginate($perPage);

            return response()->json([
                'success' => true,
                'message' => 'Submissions retrieved successfully',
                'data' => [
                    'submissions' => $submissions->items(),
                    'pagination' => [
                        'total' => $submissions->total(),
                        'per_page' => $submissions->perPage(),
                        'current_page' => $submissions->currentPage(),
                        'last_page' => $submissions->lastPage(),
                        'from' => $submissions->firstItem(),
                        'to' => $submissions->lastItem(),
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve submissions', [
                'error' => $e->getMessage(),
                'admin_id' => auth()->id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve submissions',
                'data' => config('app.debug') ? ['error' => $e->getMessage()] : null
            ], 500);
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

            return response()->json([
                'success' => true,
                'message' => 'Submission retrieved successfully',
                'data' => $submission
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Submission not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve submission', [
                'submission_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve submission',
                'data' => config('app.debug') ? ['error' => $e->getMessage()] : null
            ], 500);
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
                return response()->json([
                    'success' => false,
                    'message' => 'Can only review submissions that are in submitted status'
                ], 422);
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

            return response()->json([
                'success' => true,
                'message' => 'Submission status updated successfully',
                'data' => $submission->load(['template', 'user:id,name,email', 'reviewer:id,name'])
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Submission not found'
            ], 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update submission status', [
                'submission_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update submission status',
                'data' => config('app.debug') ? ['error' => $e->getMessage()] : null
            ], 500);
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

            return response()->json([
                'success' => true,
                'message' => 'Comment added successfully',
                'data' => $submission->load(['template', 'user:id,name,email'])
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Submission not found'
            ], 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Failed to add comment', [
                'submission_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to add comment',
                'data' => config('app.debug') ? ['error' => $e->getMessage()] : null
            ], 500);
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

            return response()->json([
                'success' => true,
                'message' => 'Statistics retrieved successfully',
                'data' => [
                    'stats' => $stats,
                    'recent_submissions' => $recentSubmissions,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve statistics', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve statistics',
                'data' => config('app.debug') ? ['error' => $e->getMessage()] : null
            ], 500);
        }
    }
}
