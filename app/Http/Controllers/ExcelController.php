<?php

namespace App\Http\Controllers;

use App\Exports\FormSubmissionsExport;
use App\Exports\FormTemplateExport;
use App\Imports\FormSubmissionsImport;
use App\Models\FormTemplate;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
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
        try {
            $filters = $request->only(['form_template_id', 'status', 'user_id', 'date_from', 'date_to']);
            
            // Validate form_template_id if provided
            if (!empty($filters['form_template_id'])) {
                $template = FormTemplate::find($filters['form_template_id']);
                if (!$template) {
                    return $this->notFoundResponse('Form template not found');
                }
            }
            
            $fileName = 'form_submissions_' . date('Y-m-d_His') . '.xlsx';

            Log::info('Exporting form submissions', [
                'filters' => $filters,
                'user_id' => auth()->id()
            ]);

            return Excel::download(new FormSubmissionsExport($filters), $fileName);

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
        try {
            $validated = $request->validate([
                'file' => 'required|file|mimes:xlsx,xls|max:10240',
                'form_template_id' => 'required|exists:form_templates,id',
            ]);

            $formTemplate = FormTemplate::findOrFail($validated['form_template_id']);

            // Check if template is active
            if ($formTemplate->status !== FormTemplate::STATUS_ACTIVE) {
                return $this->errorResponse(
                    'Cannot import to inactive form template',
                    ['status' => $formTemplate->status],
                    422
                );
            }

            $file = $request->file('file');
            $import = new FormSubmissionsImport($validated['form_template_id'], auth()->id());

            Excel::import($import, $file);

            $stats = $import->getStats();

            Log::info('Form submissions imported', [
                'template_id' => $validated['form_template_id'],
                'stats' => $stats,
                'user_id' => auth()->id()
            ]);

            return $this->successResponse('Import completed', [
                'imported' => $stats['imported'],
                'skipped' => $stats['skipped'],
                'total' => $stats['imported'] + $stats['skipped'],
                'errors' => $stats['errors']
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
            $validated = $request->validate([
                'file' => 'required|file|mimes:xlsx,xls|max:10240',
                'form_template_id' => 'required|exists:form_templates,id',
            ]);

            $formTemplate = FormTemplate::with('fields')->findOrFail($validated['form_template_id']);
            $file = $request->file('file');

            // Read all rows
            $data = Excel::toArray(new FormSubmissionsImport($validated['form_template_id'], auth()->id()), $file);
            $rows = $data[0] ?? [];
            
            $preview = array_slice($rows, 0, 6); // Header + 5 rows
            $totalRows = count($rows) - 1; // Exclude header
            
            // Validate all rows
            $errors = [];
            $validRows = 0;
            
            foreach ($rows as $index => $row) {
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
}
