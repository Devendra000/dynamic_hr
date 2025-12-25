<?php

namespace App\Http\Controllers;

use App\Exports\FormSubmissionsExport;
use App\Exports\FormTemplateExport;
use App\Imports\FormSubmissionsImport;
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
            
            // Validate form_template_id if provided
            if (!empty($filters['form_template_id'])) {
                $template = FormTemplate::find($filters['form_template_id']);
                if (!$template) {
                    return $this->notFoundResponse('Form template not found');
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
     * Download Excel template for a form
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
     *     @OA\Response(response=404, description="Form template not found")
     * )
     */
    public function downloadTemplate(string $id): BinaryFileResponse|JsonResponse
    {
        try {
            $template = FormTemplate::with('fields')->findOrFail($id);
            
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
     *         description="Import completed with statistics"
     *     ),
     *     @OA\Response(response=422, description="Validation error")
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

            $file = $request->file('file');
            
            // Step 1: Store file first (fast upload)
            $filename = 'imports/' . uniqid() . '_' . time() . '_' . $file->getClientOriginalName();
            $filePath = $file->storeAs('imports', basename($filename));

            // Step 2: Validate headers from stored file
            $storedFilePath = Storage::path($filePath);
            $headerValidation = $this->validateImportHeadersFromPath($storedFilePath, $formTemplate);
            if (!$headerValidation['valid']) {
                // Delete the uploaded file since headers are invalid
                Storage::delete($filePath);
                return $this->validationErrorResponse(
                    'Invalid file headers',
                    ['headers' => $headerValidation['errors']]
                );
            }

            // Step 3: Create import record
            $importId = DB::table('form_imports')->insertGetId([
                'form_template_id' => $validated['form_template_id'],
                'user_id' => auth()->id(),
                'filename' => $file->getClientOriginalName(),
                'file_path' => $filePath,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Step 4: Dispatch job to queue
            ProcessFormImport::dispatch(
                $filePath,
                $validated['form_template_id'],
                auth()->id(),
                $importId
            );

            Log::info('Form import job dispatched', [
                'template_id' => $validated['form_template_id'],
                'import_id' => $importId,
                'user_id' => auth()->id()
            ]);

            return $this->successResponse('Import started successfully. Processing in background.', [
                'import_id' => $importId,
                'status' => 'pending',
                'message' => 'Your file is being processed. You can check the status using the import ID.'
            ]);

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
     */
    public function getImportStatus($importId): JsonResponse
    {
        try {
            $import = DB::table('form_imports')
                ->where('id', $importId)
                ->where('user_id', auth()->id())
                ->first();

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
     */
    public function getUserImports(Request $request): JsonResponse
    {
        try {
            $query = DB::table('form_imports')
                ->where('user_id', auth()->id())
                ->orderBy('created_at', 'desc');

            if ($request->has('status')) {
                $query->where('status', $request->status);
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
     */
    public function retryImport(int $importId): JsonResponse
    {
        try {
            $import = FormImport::where('id', $importId)
                ->where('user_id', auth()->id())
                ->first();

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
}
