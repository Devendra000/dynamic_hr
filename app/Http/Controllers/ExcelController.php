<?php

namespace App\Http\Controllers;

use App\Exports\FormSubmissionsExport;
use App\Exports\FormTemplateExport;
use App\Imports\FormSubmissionsImport;
use App\Imports\QueuedFormSubmissionsImport;
use App\Jobs\ProcessFormImport;
use App\Models\FormImport;
use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExcelController extends Controller
{
    use ApiResponse;
    /**
     * Export form submissions to Excel
     *
     * @OA\Get(
     *     path="/admin/submissions/export",
     *     tags={"Excel Import/Export"},
     *     summary="Export submissions to Excel",
     *     description="Download all submissions or filtered submissions as Excel file (Admin/HR only)",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="form_template_id",
     *         in="query",
     *         description="Filter by template ID",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="status",
     *         in="query",
     *         description="Filter by status",
     *         required=false,
     *         @OA\Schema(type="string", enum={"draft", "submitted", "approved", "rejected"})
     *     ),
     *     @OA\Parameter(
     *         name="user_id",
     *         in="query",
     *         description="Filter by user ID",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="date_from",
     *         in="query",
     *         description="Filter from date (YYYY-MM-DD)",
     *         required=false,
     *         @OA\Schema(type="string", format="date")
     *     ),
     *     @OA\Parameter(
     *         name="date_to",
     *         in="query",
     *         description="Filter to date (YYYY-MM-DD)",
     *         required=false,
     *         @OA\Schema(type="string", format="date")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Excel file download",
     *         @OA\MediaType(
     *             mediaType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
     *         )
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="Forbidden - No permission to export submissions"
     *     )
     * )
     *
     * @OA\Get(
     *     path="/employee/submissions/export",
     *     tags={"Excel Import/Export"},
     *     summary="Export my submissions to Excel",
     *     description="Download employee's own form submissions as Excel file",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="form_template_id",
     *         in="query",
     *         description="Filter by template ID",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="status",
     *         in="query",
     *         description="Filter by status",
     *         required=false,
     *         @OA\Schema(type="string", enum={"draft", "submitted", "approved", "rejected"})
     *     ),
     *     @OA\Parameter(
     *         name="date_from",
     *         in="query",
     *         description="Filter from date (YYYY-MM-DD)",
     *         required=false,
     *         @OA\Schema(type="string", format="date")
     *     ),
     *     @OA\Parameter(
     *         name="date_to",
     *         in="query",
     *         description="Filter to date (YYYY-MM-DD)",
     *         required=false,
     *         @OA\Schema(type="string", format="date")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Excel file download",
     *         @OA\MediaType(
     *             mediaType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
     *         )
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="Forbidden - No permission to export submissions"
     *     )
     * )
     */
    public function exportSubmissions(Request $request): BinaryFileResponse|JsonResponse
    {
        // Increase limits for large exports
        set_time_limit(600); // 10 minutes
        ini_set('memory_limit', '2G'); // 2GB for exports
        
        try {
            $filters = $request->only(['form_template_id', 'status', 'user_id', 'date_from', 'date_to']);
            
            // Validate form_template_id if provided and check permissions
            if (!empty($filters['form_template_id'])) {
                $template = FormTemplate::find($filters['form_template_id']);
                if (!$template) {
                    return $this->notFoundResponse('Form template not found');
                }

                // Check permissions for user-specific templates
                $user = auth()->user();
                if ($template->isUserSpecific()) {
                    if ($template->template_type === FormTemplate::TEMPLATE_TYPE_KYE) {
                        // KYE templates: Only the assigned employee can export their own submissions
                        if ($template->assigned_to !== $user->id) {
                            return $this->forbiddenResponse('You do not have permission to export submissions from this assessment form');
                        }
                    } elseif ($template->template_type === FormTemplate::TEMPLATE_TYPE_KYA) {
                        // KYA templates: Only Admin and HR can export evaluation submissions
                        if (!$user->hasRole(['admin', 'hr'])) {
                            return $this->forbiddenResponse('Only administrators and HR personnel can export evaluation submissions');
                        }
                    }
                } else {
                    // Main templates: Admin/HR can export all submissions, employees can only export their own
                    if (!$user->hasRole(['admin', 'hr'])) {
                        // Force filter to only user's own submissions for main templates
                        $filters['user_id'] = $user->id;
                    }
                }
            }
            
            // Check row count to determine format
            $query = FormSubmission::query();
            if (!empty($filters['form_template_id'])) {
                $query->where('form_template_id', $filters['form_template_id']);
            }
            if (!empty($filters['status'])) {
                $query->where('status', $filters['status']);
            }
            if (!empty($filters['user_id'])) {
                $query->where('user_id', $filters['user_id']);
            }
            if (!empty($filters['date_from'])) {
                $query->whereDate('created_at', '>=', $filters['date_from']);
            }
            if (!empty($filters['date_to'])) {
                $query->whereDate('created_at', '<=', $filters['date_to']);
            }
            
            $rowCount = $query->count();
            
            // Use CSV for large datasets (>10k rows) for speed
            $format = $request->input('format', 'auto');
            if ($format === 'auto') {
                $format = $rowCount > 10000 ? 'csv' : 'xlsx';
            }
            
            $extension = $format === 'csv' ? 'csv' : 'xlsx';
            $fileName = 'form_submissions_' . date('Y-m-d_His') . '.' . $extension;

            Log::info('Exporting form submissions', [
                'filters' => $filters,
                'row_count' => $rowCount,
                'format' => $format,
                'user_id' => auth()->id()
            ]);

            return Excel::download(
                new FormSubmissionsExport($filters), 
                $fileName,
                $format === 'csv' ? \Maatwebsite\Excel\Excel::CSV : \Maatwebsite\Excel\Excel::XLSX
            );

        } catch (\Exception $e) {
            Log::error('Failed to export submissions', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return $this->serverErrorResponse(
                'Failed to export submissions',
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Check template permissions for debugging
     *
     * @OA\Get(
     *     path="/admin/form-templates/{id}/permissions",
     *     tags={"Excel Import/Export"},
     *     summary="Check template permissions",
     *     description="Debug endpoint to check template permissions and user roles",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Form template ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Permission details"
     *     )
     * )
     */
    public function checkTemplatePermissions(string $id): JsonResponse
    {
        try {
            $template = FormTemplate::findOrFail($id);
            $user = auth()->user();

            $permissions = [
                'template' => [
                    'id' => $template->id,
                    'title' => $template->title,
                    'type' => $template->template_type,
                    'is_user_specific' => $template->isUserSpecific(),
                    'assigned_to' => $template->assigned_to,
                    'status' => $template->status,
                ],
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'roles' => $user->roles->pluck('name')->toArray(),
                    'permissions' => $user->getAllPermissions()->pluck('name')->toArray(),
                    'has_admin_role' => $user->hasRole('admin'),
                    'has_hr_role' => $user->hasRole('hr'),
                ],
                'access_checks' => [
                    'is_user_specific' => $template->isUserSpecific(),
                    'is_kye_template' => $template->template_type === FormTemplate::TEMPLATE_TYPE_KYE,
                    'is_kya_template' => $template->template_type === FormTemplate::TEMPLATE_TYPE_KYA,
                    'is_main_template' => $template->template_type === FormTemplate::TEMPLATE_TYPE_MAIN,
                    'is_assigned_to_user' => $template->assigned_to === $user->id,
                    'user_has_admin_hr_roles' => $user->hasRole(['admin', 'hr']),
                    'template_is_active' => $template->status === FormTemplate::STATUS_ACTIVE,
                ],
                'can_download' => false,
                'reason' => ''
            ];

            // Determine if user can download
            if ($template->isUserSpecific()) {
                if ($template->template_type === FormTemplate::TEMPLATE_TYPE_KYE) {
                    if ($template->assigned_to === $user->id) {
                        $permissions['can_download'] = true;
                        $permissions['reason'] = 'User is assigned to this KYE template';
                    } else {
                        $permissions['reason'] = 'KYE templates can only be downloaded by the assigned employee';
                    }
                } elseif ($template->template_type === FormTemplate::TEMPLATE_TYPE_KYA) {
                    if ($user->hasRole(['admin', 'hr'])) {
                        $permissions['can_download'] = true;
                        $permissions['reason'] = 'User has admin or HR role for KYA template';
                    } else {
                        $permissions['reason'] = 'KYA templates can only be downloaded by admin or HR personnel';
                    }
                }
            } else {
                if ($user->hasRole(['admin', 'hr'])) {
                    $permissions['can_download'] = true;
                    $permissions['reason'] = 'User has admin or HR role for main template';
                } elseif ($template->status === FormTemplate::STATUS_ACTIVE) {
                    $permissions['can_download'] = true;
                    $permissions['reason'] = 'Main template is active and user can download';
                } else {
                    $permissions['reason'] = 'Main template is not active';
                }
            }

            return $this->successResponse('Permission check completed', $permissions);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->notFoundResponse('Form template not found');
        } catch (\Exception $e) {
            return $this->serverErrorResponse('Failed to check permissions', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Download Excel template
     *
     * @OA\Get(
     *     path="/admin/form-templates/{id}/excel-template",
     *     tags={"Excel Import/Export"},
     *     summary="Download Excel template",
     *     description="Download a sample Excel template for importing submissions (Admin/HR only)",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Form template ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Excel template download",
     *         @OA\MediaType(
     *             mediaType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Form template not found"
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="Forbidden - No permission to download template"
     *     )
     * )
     *
     * @OA\Get(
     *     path="/employee/templates/{id}/excel-template",
     *     tags={"Excel Import/Export"},
     *     summary="Download Excel template for KYE forms",
     *     description="Download Excel template for assigned KYE (Know Your Employee) forms",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Form template ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Excel template download",
     *         @OA\MediaType(
     *             mediaType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Form template not found"
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="Forbidden - Not assigned to this KYE template"
     *     )
     * )
     */
    public function downloadTemplate(string $id): BinaryFileResponse|JsonResponse
    {
        try {
            $template = FormTemplate::with('fields')->findOrFail($id);
            $user = auth()->user();

            // Debug logging for permissions
            Log::info('Template download permission check', [
                'template_id' => $id,
                'template_type' => $template->template_type,
                'template_assigned_to' => $template->assigned_to,
                'is_user_specific' => $template->isUserSpecific(),
                'user_id' => $user->id,
                'user_roles' => $user->roles->pluck('name')->toArray(),
                'user_permissions' => $user->getAllPermissions()->pluck('name')->toArray()
            ]);

            // Check permissions for user-specific templates
            if ($template->isUserSpecific()) {
                if ($template->template_type === FormTemplate::TEMPLATE_TYPE_KYE) {
                    // KYE templates: Only the assigned employee can download
                    if ($template->assigned_to !== $user->id) {
                        Log::warning('KYE template download denied', [
                            'template_id' => $id,
                            'assigned_to' => $template->assigned_to,
                            'user_id' => $user->id,
                            'user_roles' => $user->roles->pluck('name')->toArray()
                        ]);
                        return $this->forbiddenResponse('You do not have permission to download this assessment form template. Only the assigned employee can download KYE templates.');
                    }
                } elseif ($template->template_type === FormTemplate::TEMPLATE_TYPE_KYA) {
                    // KYA templates: Only Admin and HR can download
                    if (!$user->hasRole(['admin', 'hr'])) {
                        Log::warning('KYA template download denied', [
                            'template_id' => $id,
                            'user_roles' => $user->roles->pluck('name')->toArray()
                        ]);
                        return $this->forbiddenResponse('Only administrators and HR personnel can download evaluation form templates.');
                    }
                }
            } else {
                // Main templates: Admin/HR can download, employees can only download if they have access to submit
                if (!$user->hasRole(['admin', 'hr'])) {
                    // For main templates, employees can download if the template is active
                    // This allows them to see what fields are required
                    if ($template->status !== FormTemplate::STATUS_ACTIVE) {
                        return $this->forbiddenResponse('This form template is not currently available');
                    }
                }
            }

            $fileName = str_replace(' ', '_', strtolower($template->title)) . '_template_' . date('Y-m-d') . '.xlsx';

            Log::info('Downloading form template', [
                'template_id' => $id,
                'user_id' => auth()->id()
            ]);

            return Excel::download(new FormTemplateExport($id), $fileName);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->notFoundResponse('Form template not found');
        } catch (\Exception $e) {
            Log::error('Failed to download template', [
                'template_id' => $id,
                'error' => $e->getMessage()
            ]);

            return $this->serverErrorResponse(
                'Failed to download template',
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Import form submissions from Excel
     *
     * @OA\Post(
     *     path="/admin/submissions/import",
     *     tags={"Excel Import/Export"},
     *     summary="Import submissions from Excel",
     *     description="Upload and import form submissions from Excel file (Admin/HR only)",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"file", "form_template_id"},
     *                 @OA\Property(
     *                     property="file",
     *                     type="string",
     *                     format="binary",
     *                     description="Excel file (.xlsx or .xls)"
     *                 ),
     *                 @OA\Property(
     *                     property="form_template_id",
     *                     type="integer",
     *                     description="Form template ID",
     *                     example=1
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Import completed with statistics (small files) or background job started (large files)",
     *         @OA\JsonContent(
     *             oneOf={
     *                 @OA\Schema(
     *                     type="object",
     *                     @OA\Property(property="success", type="boolean", example=true),
     *                     @OA\Property(property="message", type="string", example="Import completed successfully"),
     *                     @OA\Property(property="data", type="object",
     *                         @OA\Property(property="imported", type="integer", example=150),
     *                         @OA\Property(property="skipped", type="integer", example=5),
     *                         @OA\Property(property="errors", type="array", @OA\Items(type="string")),
     *                         @OA\Property(property="method", type="string", example="direct")
     *                     )
     *                 ),
     *                 @OA\Schema(
     *                     type="object",
     *                     @OA\Property(property="success", type="boolean", example=true),
     *                     @OA\Property(property="message", type="string", example="Import started successfully. Processing in background."),
     *                     @OA\Property(property="data", type="object",
     *                         @OA\Property(property="import_id", type="integer", example=123),
     *                         @OA\Property(property="method", type="string", example="background"),
     *                         @OA\Property(property="note", type="string", example="Large file detected - processing in background for better performance")
     *                     )
     *                 )
     *             }
     *         )
     *     ),
     *     @OA\Response(response=422, description="Validation error"),
     *     @OA\Response(
     *         response=403,
     *         description="Forbidden - No permission to import submissions"
     *     )
     * )
     *
     * @OA\Post(
     *     path="/employee/submissions/import",
     *     tags={"Excel Import/Export"},
     *     summary="Import submissions from Excel",
     *     description="Upload and import form submissions from Excel file for assigned templates",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"file", "form_template_id"},
     *                 @OA\Property(
     *                     property="file",
     *                     type="string",
     *                     format="binary",
     *                     description="Excel file (.xlsx or .xls)"
     *                 ),
     *                 @OA\Property(
     *                     property="form_template_id",
     *                     type="integer",
     *                     description="Form template ID (must be assigned to user for KYE forms)",
     *                     example=1
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Import completed with statistics (small files) or background job started (large files)",
     *         @OA\JsonContent(
     *             oneOf={
     *                 @OA\Schema(
     *                     type="object",
     *                     @OA\Property(property="success", type="boolean", example=true),
     *                     @OA\Property(property="message", type="string", example="Import completed successfully"),
     *                     @OA\Property(property="data", type="object",
     *                         @OA\Property(property="imported", type="integer", example=1),
     *                         @OA\Property(property="skipped", type="integer", example=0),
     *                         @OA\Property(property="errors", type="array", @OA\Items(type="string")),
     *                         @OA\Property(property="method", type="string", example="direct")
     *                     )
     *                 ),
     *                 @OA\Schema(
     *                     type="object",
     *                     @OA\Property(property="success", type="boolean", example=true),
     *                     @OA\Property(property="message", type="string", example="Import started successfully. Processing in background."),
     *                     @OA\Property(property="data", type="object",
     *                         @OA\Property(property="import_id", type="integer", example=123),
     *                         @OA\Property(property="method", type="string", example="background"),
     *                         @OA\Property(property="note", type="string", example="Large file detected - processing in background for better performance")
     *                     )
     *                 )
     *             }
     *         )
     *     ),
     *     @OA\Response(response=422, description="Validation error"),
     *     @OA\Response(
     *         response=403,
     *         description="Forbidden - No permission to import to this template"
     *     )
     * )
     */
    public function importSubmissions(Request $request): JsonResponse
    {
        // Increase limits BEFORE any processing
        set_time_limit(600); // 10 minutes
        ini_set('memory_limit', '1G');
        ini_set('max_execution_time', '600');
        
        try {
            // Check if file was uploaded
            if (!$request->hasFile('file')) {
                return $this->validationErrorResponse(
                    'Validation failed',
                    ['file' => ['No file was uploaded. Please select a file to import.']]
                );
            }

            // Check if file upload was successful
            $uploadedFile = $request->file('file');
            if (!$uploadedFile->isValid()) {
                $error = $uploadedFile->getError();
                $maxSize = ini_get('upload_max_filesize');
                $postMaxSize = ini_get('post_max_size');
                
                $errorMessages = [
                    UPLOAD_ERR_INI_SIZE => "The file size exceeds the server's maximum upload size of {$maxSize}.",
                    UPLOAD_ERR_FORM_SIZE => "The file size exceeds the maximum allowed size of 50MB.",
                    UPLOAD_ERR_PARTIAL => "The file was only partially uploaded. Please try again.",
                    UPLOAD_ERR_NO_FILE => "No file was uploaded.",
                    UPLOAD_ERR_NO_TMP_DIR => "Server error: Missing temporary upload folder.",
                    UPLOAD_ERR_CANT_WRITE => "Server error: Failed to write file to disk.",
                    UPLOAD_ERR_EXTENSION => "Server error: File upload was stopped by a PHP extension.",
                ];
                
                $errorMessage = $errorMessages[$error] ?? 'The file failed to upload. Please try again.';
                
                return $this->validationErrorResponse(
                    'File upload failed',
                    ['file' => [$errorMessage . " (Server upload_max_filesize: {$maxSize}, post_max_size: {$postMaxSize})"]]
                );
            }

            $validated = $request->validate([
                'file' => 'required|file|mimes:xlsx,xls,csv,txt|mimetypes:text/csv,text/plain,application/csv,text/comma-separated-values,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet|max:51200',
                'form_template_id' => 'required|exists:form_templates,id',
            ], [
                'file.required' => 'Please upload a file to import.',
                'file.file' => 'The uploaded file is invalid.',
                'file.mimes' => 'The file must be an Excel file (.xlsx, .xls) or CSV file (.csv).',
                'file.mimetypes' => 'Invalid file type. Only Excel (.xlsx, .xls) and CSV files are allowed.',
                'file.max' => 'The file size exceeds the maximum allowed limit of 50MB. Your file is too large.',
                'form_template_id.required' => 'Form template ID is required.',
                'form_template_id.exists' => 'The selected form template does not exist.',
            ]);

            $formTemplate = FormTemplate::with('fields')->findOrFail($validated['form_template_id']);

            // Check if template is active
            if ($formTemplate->status !== FormTemplate::STATUS_ACTIVE) {
                return $this->errorResponse(
                    'Cannot import to inactive form template',
                    ['status' => $formTemplate->status],
                    422
                );
            }

            // Check permissions for user-specific templates
            $user = auth()->user();
            if ($formTemplate->isUserSpecific()) {
                if ($formTemplate->template_type === FormTemplate::TEMPLATE_TYPE_KYE) {
                    // KYE templates: Only the assigned employee can import their own submissions
                    if ($formTemplate->assigned_to !== $user->id) {
                        return $this->forbiddenResponse('You do not have permission to import submissions to this assessment form');
                    }
                } elseif ($formTemplate->template_type === FormTemplate::TEMPLATE_TYPE_KYA) {
                    // KYA templates: Only Admin and HR can import evaluation submissions
                    if (!$user->hasRole(['admin', 'hr'])) {
                        return $this->forbiddenResponse('Only administrators and HR personnel can import evaluation submissions');
                    }
                }
            } else {
                // Main templates: Admin/HR can import for anyone, employees can only import their own submissions
                if (!$user->hasRole(['admin', 'hr'])) {
                    // For main templates, employees can only import if they have submissions to that template
                    // This is a business rule - employees typically shouldn't bulk import to main templates
                    return $this->forbiddenResponse('You do not have permission to import submissions to this form template');
                }
            }

            $file = $request->file('file');

            // Step 1: Store file temporarily to count rows
            $tempFilename = 'temp_' . uniqid() . '_' . time() . '_' . $file->getClientOriginalName();
            $tempFilePath = $file->storeAs('temp', $tempFilename);
            $storedTempPath = Storage::path($tempFilePath);

            // Step 2: Count total rows in the file
            $totalRows = $this->countRowsInFile($storedTempPath);

            // Step 3: Decide import strategy based on row count
            if ($totalRows < 1000) {
                // Small file - import directly
                return $this->importDirectly($storedTempPath, $validated['form_template_id'], $formTemplate, $file->getClientOriginalName());
            } else {
                // Large file - use background processing
                return $this->importViaBackgroundJob($tempFilePath, $validated['form_template_id'], $formTemplate, $file->getClientOriginalName(), $tempFilename);
            }

        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->validationErrorResponse('Validation failed', $e->errors());
        } catch (\Exception $e) {
            Log::error('Failed to import submissions', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return $this->serverErrorResponse(
                'Failed to import submissions',
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Validate Excel file before import
     *
     * @OA\Post(
     *     path="/admin/submissions/import/validate",
     *     tags={"Excel Import/Export"},
     *     summary="Validate Excel import",
     *     description="Preview and validate Excel file before actual import (Admin/HR only)",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"file", "form_template_id"},
     *                 @OA\Property(
     *                     property="file",
     *                     type="string",
     *                     format="binary",
     *                     description="Excel file (.xlsx or .xls)"
     *                 ),
     *                 @OA\Property(
     *                     property="form_template_id",
     *                     type="integer",
     *                     description="Form template ID"
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Validation results with preview"
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="Forbidden - No permission to validate imports"
     *     )
     * )
     *
     * @OA\Post(
     *     path="/employee/submissions/import/validate",
     *     tags={"Excel Import/Export"},
     *     summary="Validate Excel import",
     *     description="Preview and validate Excel file before actual import for assigned templates",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"file", "form_template_id"},
     *                 @OA\Property(
     *                     property="file",
     *                     type="string",
     *                     format="binary",
     *                     description="Excel file (.xlsx or .xls)"
     *                 ),
     *                 @OA\Property(
     *                     property="form_template_id",
     *                     type="integer",
     *                     description="Form template ID (must be assigned to user for KYE forms)"
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Validation results with preview"
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="Forbidden - No permission to validate imports for this template"
     *     )
     * )
     */
    public function validateImport(Request $request): JsonResponse
    {
        try {
            // Check if file was uploaded
            if (!$request->hasFile('file')) {
                return $this->validationErrorResponse(
                    'Validation failed',
                    ['file' => ['No file was uploaded. Please select a file to validate.']]
                );
            }

            // Check if file upload was successful
            $uploadedFile = $request->file('file');
            if (!$uploadedFile->isValid()) {
                $error = $uploadedFile->getError();
                $maxSize = ini_get('upload_max_filesize');
                $postMaxSize = ini_get('post_max_size');
                
                $errorMessages = [
                    UPLOAD_ERR_INI_SIZE => "The file size exceeds the server's maximum upload size of {$maxSize}.",
                    UPLOAD_ERR_FORM_SIZE => "The file size exceeds the maximum allowed size of 50MB.",
                    UPLOAD_ERR_PARTIAL => "The file was only partially uploaded. Please try again.",
                    UPLOAD_ERR_NO_FILE => "No file was uploaded.",
                    UPLOAD_ERR_NO_TMP_DIR => "Server error: Missing temporary upload folder.",
                    UPLOAD_ERR_CANT_WRITE => "Server error: Failed to write file to disk.",
                    UPLOAD_ERR_EXTENSION => "Server error: File upload was stopped by a PHP extension.",
                ];
                
                $errorMessage = $errorMessages[$error] ?? 'The file failed to upload. Please try again.';
                
                return $this->validationErrorResponse(
                    'File upload failed',
                    ['file' => [$errorMessage . " (Server limits: upload_max_filesize={$maxSize}, post_max_size={$postMaxSize})"]]
                );
            }

            $validated = $request->validate([
                'file' => 'required|file|mimes:xlsx,xls,csv,txt|mimetypes:text/csv,text/plain,application/csv,text/comma-separated-values,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet|max:51200',
                'form_template_id' => 'required|exists:form_templates,id',
            ], [
                'file.required' => 'Please upload a file to validate.',
                'file.file' => 'The uploaded file is invalid.',
                'file.mimes' => 'The file must be an Excel file (.xlsx, .xls) or CSV file (.csv).',
                'file.mimetypes' => 'Invalid file type. Only Excel (.xlsx, .xls) and CSV files are allowed.',
                'file.max' => 'The file size exceeds the maximum allowed limit of 50MB. Your file is too large.',
                'form_template_id.required' => 'Form template ID is required.',
                'form_template_id.exists' => 'The selected form template does not exist.',
            ]);

            $formTemplate = FormTemplate::with('fields')->findOrFail($validated['form_template_id']);

            // Check permissions for user-specific templates
            $user = auth()->user();
            if ($formTemplate->isUserSpecific()) {
                if ($formTemplate->template_type === FormTemplate::TEMPLATE_TYPE_KYE) {
                    // KYE templates: Only the assigned employee can validate imports
                    if ($formTemplate->assigned_to !== $user->id) {
                        return $this->forbiddenResponse('You do not have permission to validate imports for this assessment form');
                    }
                } elseif ($formTemplate->template_type === FormTemplate::TEMPLATE_TYPE_KYA) {
                    // KYA templates: Only Admin and HR can validate evaluation imports
                    if (!$user->hasRole(['admin', 'hr'])) {
                        return $this->forbiddenResponse('Only administrators and HR personnel can validate evaluation form imports');
                    }
                }
            } else {
                // Main templates: Admin/HR can validate for anyone, employees cannot validate imports
                if (!$user->hasRole(['admin', 'hr'])) {
                    return $this->forbiddenResponse('You do not have permission to validate imports for this form template');
                }
            }

            $file = $request->file('file');

            // For large files, skip full validation (too slow)
            $fileSize = $file->getSize();
            $isLargeFile = $fileSize > 1048576; // > 1MB
            
            if ($isLargeFile) {
                return $this->successResponse(
                    'Large file detected - validation skipped for performance',
                    [
                        'template' => [
                            'id' => $formTemplate->id,
                            'title' => $formTemplate->title,
                            'fields_count' => $formTemplate->fields->count()
                        ],
                        'file_size' => round($fileSize / 1024 / 1024, 2) . ' MB',
                        'large_file' => true,
                        'note' => 'File is too large for preview validation. Proceed with import directly. Any errors will be reported after processing.',
                        'validation' => [
                            'valid' => null,
                            'skipped' => true
                        ]
                    ]
                );
            }

            // Increase memory limit temporarily for small files only
            ini_set('memory_limit', '512M');
            
            // Read and validate small files
            $data = Excel::toArray(new FormSubmissionsImport($validated['form_template_id'], auth()->id()), $file);
            $rows = $data[0] ?? [];
            
            $totalRows = count($rows) - 1; // Exclude header
            $sampleSize = min(100, $totalRows); // Validate max 100 rows for small files
            $rowsToValidate = array_slice($rows, 0, $sampleSize + 1); // +1 for header
            
            $preview = array_slice($rows, 0, 6); // Header + 5 rows
            
            // Validate sample rows
            $errors = [];
            $validRows = 0;
            
            foreach ($rowsToValidate as $index => $row) {
                if ($index === 0) continue; // Skip header
                
                $rowNumber = $index + 1;
                $rowErrors = [];
                
                // Check each field
                foreach ($formTemplate->fields as $field) {
                    $fieldLabel = strtolower(str_replace(' ', '_', $field->label));
                    $value = $row[$fieldLabel] ?? null;
                    
                    // Check required fields
                    if ($field->is_required && (empty($value) && $value !== '0')) {
                        $rowErrors[] = "Required field '{$field->label}' is missing";
                    }
                    
                    // Validate field type if value exists
                    if (!empty($value) || $value === '0') {
                        switch ($field->field_type) {
                            case 'email':
                                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                                    $rowErrors[] = "Invalid email format in '{$field->label}'";
                                }
                                break;
                            
                            case 'number':
                                if (!is_numeric($value)) {
                                    $rowErrors[] = "Invalid number format in '{$field->label}'";
                                }
                                break;
                            
                            case 'date':
                                try {
                                    \Carbon\Carbon::parse($value);
                                } catch (\Exception $e) {
                                    $rowErrors[] = "Invalid date format in '{$field->label}'";
                                }
                                break;
                            
                            case 'dropdown':
                            case 'radio':
                                if (!empty($field->options) && !in_array($value, $field->options)) {
                                    $rowErrors[] = "Invalid option '{$value}' for '{$field->label}'. Allowed: " . implode(', ', $field->options);
                                }
                                break;
                        }
                    }
                }
                
                if (!empty($rowErrors)) {
                    $errors[] = [
                        'row' => $rowNumber,
                        'errors' => $rowErrors
                    ];
                } else {
                    $validRows++;
                }
            }
            
            $isValid = empty($errors);

            return $this->successResponse(
                $isValid ? 'File validated successfully' : 'Validation errors found',
                [
                    'template' => [
                        'id' => $formTemplate->id,
                        'title' => $formTemplate->title,
                        'fields_count' => $formTemplate->fields->count()
                    ],
                    'preview' => $preview,
                    'total_rows' => $totalRows,
                    'validated_rows' => $sampleSize,
                    'is_sample' => $totalRows > 100,
                    'sample_note' => $totalRows > 100 ? 'Only first 100 rows validated for performance' : null,
                    'valid_rows' => $validRows,
                    'invalid_rows' => count($errors),
                    'validation' => [
                        'valid' => $isValid,
                        'errors' => $errors
                    ]
                ]
            );

        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->validationErrorResponse('Validation failed', $e->errors());
        } catch (\Exception $e) {
            Log::error('Failed to validate import', [
                'error' => $e->getMessage()
            ]);

            return $this->serverErrorResponse(
                'Failed to validate file',
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Validate import file headers against form template fields
     */
    protected function validateImportHeadersFromPath($filePath, $formTemplate)
    {
        try {
            // Read headers from the file
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($filePath);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($filePath);
            $worksheet = $spreadsheet->getActiveSheet();
            
            $headers = [];
            foreach ($worksheet->getRowIterator(1, 1) as $row) {
                $cellIterator = $row->getCellIterator();
                $cellIterator->setIterateOnlyExistingCells(false);
                foreach ($cellIterator as $cell) {
                    $value = trim($cell->getValue());
                    if (!empty($value)) {
                        $headers[] = strtolower(str_replace(' ', '_', $value));
                    }
                }
            }

            // Get expected headers from form template
            $expectedHeaders = [];
            $expectedHeadersDisplay = [];
            foreach ($formTemplate->fields as $field) {
                $expectedLabel = strtolower(str_replace(' ', '_', $field->label));
                $expectedHeaders[] = $expectedLabel;
                $expectedHeadersDisplay[] = $field->label;
            }

            // Check for missing headers
            $missingHeaders = array_diff($expectedHeaders, $headers);
            $extraHeaders = array_diff($headers, $expectedHeaders);

            $errors = [];
            if (!empty($missingHeaders)) {
                $missingDisplay = [];
                foreach ($missingHeaders as $missing) {
                    $index = array_search($missing, $expectedHeaders);
                    $missingDisplay[] = $expectedHeadersDisplay[$index];
                }
                $errors[] = 'Missing required columns: ' . implode(', ', $missingDisplay);
            }

            if (!empty($extraHeaders)) {
                $errors[] = 'Unexpected columns found: ' . implode(', ', $extraHeaders);
            }

            return [
                'valid' => empty($errors),
                'errors' => $errors,
                'found_headers' => $headers,
                'expected_headers' => $expectedHeadersDisplay
            ];

        } catch (\Exception $e) {
            return [
                'valid' => false,
                'errors' => ['Failed to read file headers: ' . $e->getMessage()]
            ];
        }
    }

    /**
     * Get import status
     *
     * @OA\Get(
     *     path="/admin/submissions/import/status/{importId}",
     *     tags={"Excel Import/Export"},
     *     summary="Get import status",
     *     description="Get the status of a background import job",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="importId",
     *         in="path",
     *         required=true,
     *         description="Import ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Import status retrieved",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Import status retrieved"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer"),
     *                 @OA\Property(property="status", type="string"),
     *                 @OA\Property(property="filename", type="string"),
     *                 @OA\Property(property="imported_count", type="integer"),
     *                 @OA\Property(property="skipped_count", type="integer"),
     *                 @OA\Property(property="errors", type="array", @OA\Items(type="object"))
     *             )
     *         )
     *     ),
     *     @OA\Response(response=404, description="Import not found"),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     *
     * @OA\Get(
     *     path="/employee/submissions/import/status/{importId}",
     *     tags={"Excel Import/Export"},
     *     summary="Get import status",
     *     description="Get the status of a background import job",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="importId",
     *         in="path",
     *         required=true,
     *         description="Import ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Import status retrieved",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Import status retrieved"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer"),
     *                 @OA\Property(property="status", type="string"),
     *                 @OA\Property(property="filename", type="string"),
     *                 @OA\Property(property="imported_count", type="integer"),
     *                 @OA\Property(property="skipped_count", type="integer"),
     *                 @OA\Property(property="errors", type="array", @OA\Items(type="object"))
     *             )
     *         )
     *     ),
     *     @OA\Response(response=404, description="Import not found"),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function getImportStatus($importId): JsonResponse
    {
        try {
            $user = auth()->user();
            
            $query = DB::table('form_imports')
                ->join('form_templates', 'form_imports.form_template_id', '=', 'form_templates.id')
                ->where('form_imports.id', $importId)
                ->where('form_imports.user_id', $user->id)
                ->select('form_imports.*', 'form_templates.template_type', 'form_templates.assigned_to');

            // For KYE templates, ensure user is assigned to the template
            if (!$user->hasRole(['admin', 'hr'])) {
                $query->where(function ($q) use ($user) {
                    $q->where('form_templates.template_type', '!=', FormTemplate::TEMPLATE_TYPE_KYE)
                      ->orWhere(function ($subQ) use ($user) {
                          $subQ->where('form_templates.template_type', FormTemplate::TEMPLATE_TYPE_KYE)
                               ->where('form_templates.assigned_to', $user->id);
                      });
                });
            }

            $import = $query->first();

            if (!$import) {
                return $this->notFoundResponse('Import not found');
            }

            return $this->successResponse('Import status retrieved', [
                'id' => $import->id,
                'form_template_id' => $import->form_template_id,
                'filename' => $import->filename,
                'status' => $import->status,
                'imported_count' => $import->imported_count,
                'skipped_count' => $import->skipped_count,
                'errors' => $import->errors ? json_decode($import->errors, true) : [],
                'started_at' => $import->started_at,
                'completed_at' => $import->completed_at,
                'created_at' => $import->created_at,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get import status', [
                'error' => $e->getMessage(),
                'import_id' => $importId
            ]);

            return $this->serverErrorResponse(
                'Failed to retrieve import status',
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Get all imports for authenticated user
     *
     * @OA\Get(
     *     path="/admin/submissions/imports",
     *     tags={"Excel Import/Export"},
     *     summary="Get user imports",
     *     description="Get all imports for the authenticated user",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="status",
     *         in="query",
     *         description="Filter by status",
     *         @OA\Schema(type="string", enum={"pending", "processing", "completed", "failed"})
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Imports retrieved",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Imports retrieved"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="object"))
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     *
     * @OA\Get(
     *     path="/employee/submissions/imports",
     *     tags={"Excel Import/Export"},
     *     summary="Get user imports",
     *     description="Get all imports for the authenticated user",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="status",
     *         in="query",
     *         description="Filter by status",
     *         @OA\Schema(type="string", enum={"pending", "processing", "completed", "failed"})
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Imports retrieved",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Imports retrieved"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="object"))
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function getUserImports(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            
            $query = DB::table('form_imports')
                ->join('form_templates', 'form_imports.form_template_id', '=', 'form_templates.id')
                ->where('form_imports.user_id', $user->id)
                ->select('form_imports.*', 'form_templates.template_type', 'form_templates.assigned_to')
                ->orderBy('form_imports.created_at', 'desc');

            // For KYE templates, ensure user is assigned to the template
            if (!$user->hasRole(['admin', 'hr'])) {
                $query->where(function ($q) use ($user) {
                    $q->where('form_templates.template_type', '!=', FormTemplate::TEMPLATE_TYPE_KYE)
                      ->orWhere(function ($subQ) use ($user) {
                          $subQ->where('form_templates.template_type', FormTemplate::TEMPLATE_TYPE_KYE)
                               ->where('form_templates.assigned_to', $user->id);
                      });
                });
            }

            if ($request->has('status')) {
                $query->where('form_imports.status', $request->status);
            }

            $imports = $query->get()->map(function ($import) {
                return [
                    'id' => $import->id,
                    'form_template_id' => $import->form_template_id,
                    'filename' => $import->filename,
                    'status' => $import->status,
                    'imported_count' => $import->imported_count,
                    'skipped_count' => $import->skipped_count,
                    'errors' => $import->errors ? json_decode($import->errors, true) : [],
                    'started_at' => $import->started_at,
                    'completed_at' => $import->completed_at,
                    'created_at' => $import->created_at,
                ];
            });

            return $this->successResponse('Imports retrieved', [
                'imports' => $imports,
                'total' => $imports->count()
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get user imports', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return $this->serverErrorResponse(
                'Failed to retrieve imports',
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Retry a failed import
     *
     * @OA\Post(
     *     path="/admin/submissions/import/{importId}/retry",
     *     tags={"Excel Import/Export"},
     *     summary="Retry failed import",
     *     description="Retry a failed import job",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="importId",
     *         in="path",
     *         required=true,
     *         description="Import ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Import retry initiated",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Import retry initiated"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="import_id", type="integer", example=1)
     *             )
     *         )
     *     ),
     *     @OA\Response(response=400, description="Import is not failed or already processing"),
     *     @OA\Response(response=404, description="Import not found"),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     *
     * @OA\Post(
     *     path="/employee/submissions/import/{importId}/retry",
     *     tags={"Excel Import/Export"},
     *     summary="Retry failed import",
     *     description="Retry a failed import job",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="importId",
     *         in="path",
     *         required=true,
     *         description="Import ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Import retry initiated",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Import retry initiated"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="import_id", type="integer", example=1)
     *             )
     *         )
     *     ),
     *     @OA\Response(response=400, description="Import is not failed or already processing"),
     *     @OA\Response(response=404, description="Import not found"),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function retryImport(int $importId): JsonResponse
    {
        try {
            $user = auth()->user();
            
            $query = FormImport::with('formTemplate')
                ->where('id', $importId)
                ->where('user_id', $user->id);

            // For KYE templates, ensure user is assigned to the template
            if (!$user->hasRole(['admin', 'hr'])) {
                $query->whereHas('formTemplate', function ($q) use ($user) {
                    $q->where('template_type', '!=', FormTemplate::TEMPLATE_TYPE_KYE)
                      ->orWhere(function ($subQ) use ($user) {
                          $subQ->where('template_type', FormTemplate::TEMPLATE_TYPE_KYE)
                               ->where('assigned_to', $user->id);
                      });
                });
            }

            $import = $query->first();

            if (!$import) {
                return $this->notFoundResponse('Import not found');
            }

            if ($import->status !== 'failed') {
                return $this->errorResponse('Only failed imports can be retried', null, 400);
            }

            // Check if file still exists
            if (!Storage::disk('local')->exists($import->file_path)) {
                return $this->errorResponse('Import file no longer exists', null, 400);
            }

            // Reset import status
            $import->update([
                'status' => 'pending',
                'started_at' => null,
                'completed_at' => null,
                'imported_count' => 0,
                'skipped_count' => 0,
                'errors' => null,
            ]);

            // Re-dispatch the job with all required parameters
            ProcessFormImport::dispatch(
                $import->file_path,
                $import->form_template_id,
                $import->user_id,
                $import->id
            );

            return $this->successResponse('Import retry initiated', [
                'import_id' => $import->id
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to retry import', [
                'error' => $e->getMessage(),
                'import_id' => $importId
            ]);

            return $this->serverErrorResponse(
                'Failed to retry import',
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Count total rows in an Excel/CSV file
     */
    protected function countRowsInFile(string $filePath): int
    {
        try {
            // Use a simple approach to count rows
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
            $worksheet = $spreadsheet->getActiveSheet();
            $highestRow = $worksheet->getHighestRow();

            return max(0, $highestRow - 1); // Subtract 1 for header row
        } catch (\Exception $e) {
            Log::warning('Failed to count rows in file using PhpSpreadsheet', [
                'file_path' => $filePath,
                'error' => $e->getMessage()
            ]);

            // Fallback: try to read as CSV
            try {
                $handle = fopen($filePath, 'r');
                $rowCount = 0;
                while (($data = fgetcsv($handle)) !== false) {
                    $rowCount++;
                }
                fclose($handle);
                return max(0, $rowCount - 1); // Subtract 1 for header row
            } catch (\Exception $e2) {
                Log::warning('Failed to count rows in file using CSV fallback', [
                    'file_path' => $filePath,
                    'error' => $e2->getMessage()
                ]);
                return 0;
            }
        }
    }

    /**
     * Import file directly (for small files < 1000 rows)
     */
    protected function importDirectly(string $filePath, int $formTemplateId, $formTemplate, string $originalFilename): JsonResponse
    {
        try {
            // Validate headers from stored file
            $headerValidation = $this->validateImportHeadersFromPath($filePath, $formTemplate);
            if (!$headerValidation['valid']) {
                // Delete the uploaded file since headers are invalid
                Storage::delete($filePath);
                return $this->validationErrorResponse(
                    'Invalid file headers',
                    ['headers' => $headerValidation['errors']]
                );
            }

            // Import directly using QueuedFormSubmissionsImport
            $import = new QueuedFormSubmissionsImport($formTemplateId, auth()->id());
            Excel::import($import, $filePath);

            // Clean up temp file
            Storage::delete($filePath);

            Log::info('Direct import completed', [
                'template_id' => $formTemplateId,
                'user_id' => auth()->id(),
                'filename' => $originalFilename,
                'imported' => $import->getImportedCount(),
                'skipped' => $import->getSkippedCount()
            ]);

            return $this->successResponse('Import completed successfully', [
                'imported' => $import->getImportedCount(),
                'skipped' => $import->getSkippedCount(),
                'errors' => $import->errors(),
                'method' => 'direct'
            ]);

        } catch (\Exception $e) {
            // Clean up temp file on error
            Storage::delete($filePath);

            Log::error('Direct import failed', [
                'error' => $e->getMessage(),
                'template_id' => $formTemplateId,
                'user_id' => auth()->id()
            ]);

            return $this->serverErrorResponse(
                'Import failed',
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Import file via background job (for large files >= 1000 rows)
     */
    protected function importViaBackgroundJob(string $tempFilePath, int $formTemplateId, $formTemplate, string $originalFilename, string $tempFilename): JsonResponse
    {
        try {
            // Get full path for validation
            $fullTempPath = Storage::path($tempFilePath);
            
            // Validate headers from temp file
            $headerValidation = $this->validateImportHeadersFromPath($fullTempPath, $formTemplate);
            if (!$headerValidation['valid']) {
                // Delete the uploaded file since headers are invalid
                Storage::delete($tempFilePath);
                return $this->validationErrorResponse(
                    'Invalid file headers',
                    ['headers' => $headerValidation['errors']]
                );
            }

            // Move file from temp to imports directory
            $finalFilename = 'imports/' . uniqid() . '_' . time() . '_' . $originalFilename;
            
            // Ensure imports directory exists
            Storage::makeDirectory('imports');
            
            Log::info('Moving file from temp to imports', [
                'from' => $tempFilePath,
                'to' => $finalFilename
            ]);
            
            // Use Storage::move with relative paths
            $moveResult = Storage::move($tempFilePath, $finalFilename);
            
            if (!$moveResult) {
                Log::error('Storage::move failed');
                Storage::delete($tempFilePath);
                return $this->serverErrorResponse('Failed to prepare file for import');
            }
            
            Log::info('File moved successfully', [
                'from' => $tempFilePath,
                'to' => $finalFilename
            ]);

            // Create import record
            $importId = DB::table('form_imports')->insertGetId([
                'form_template_id' => $formTemplateId,
                'user_id' => auth()->id(),
                'filename' => $originalFilename,
                'file_path' => $finalFilename,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Dispatch job to queue
            ProcessFormImport::dispatch(
                $finalFilename,
                $formTemplateId,
                auth()->id(),
                $importId
            );

            Log::info('Background import job dispatched', [
                'template_id' => $formTemplateId,
                'import_id' => $importId,
                'user_id' => auth()->id(),
                'method' => 'background'
            ]);

            return $this->successResponse('Import started successfully. Processing in background.', [
                'import_id' => $importId,
                'method' => 'background',
                'note' => 'Large file detected - processing in background for better performance'
            ]);

        } catch (\Exception $e) {
            // Clean up temp file on error
            Storage::delete($tempFilePath);

            Log::error('Background import setup failed', [
                'error' => $e->getMessage(),
                'template_id' => $formTemplateId,
                'user_id' => auth()->id()
            ]);

            return $this->serverErrorResponse(
                'Failed to start import',
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }
}
