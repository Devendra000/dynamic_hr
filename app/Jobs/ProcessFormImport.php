<?php

namespace App\Jobs;

use App\Models\FormTemplate;
use App\Models\FormSubmission;
use App\Models\SubmissionResponse;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\HeadingRowImport;

class ProcessFormImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $filePath;
    protected $formTemplateId;
    protected $userId;
    protected $importId;

    /**
     * Number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * Number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 3600; // 1 hour

    /**
     * Create a new job instance.
     */
    public function __construct($filePath, $formTemplateId, $userId, $importId = null)
    {
        $this->filePath = $filePath;
        $this->formTemplateId = $formTemplateId;
        $this->userId = $userId;
        $this->importId = $importId;
    }

    /**
     * Execute the job.
     */
    public function handle()
    {
        try {
            Log::info('Starting form import job', [
                'file' => $this->filePath,
                'template_id' => $this->formTemplateId,
                'user_id' => $this->userId
            ]);

            // Update import status to processing
            if ($this->importId) {
                DB::table('form_imports')->where('id', $this->importId)->update([
                    'status' => 'processing',
                    'started_at' => now(),
                ]);
            }

            // Use Maatwebsite\Excel chunked import for true memory efficiency
            $fullFilePath = Storage::path($this->filePath);
            
            // Debug: Check if file exists
            if (!file_exists($fullFilePath)) {
                throw new \Exception("File does not exist at path: {$fullFilePath}");
            }
            
            Log::info('File exists, starting Excel import', [
                'file_path' => $this->filePath,
                'full_path' => $fullFilePath,
                'file_size' => filesize($fullFilePath)
            ]);
            
            $import = new \App\Imports\QueuedFormSubmissionsImport($this->formTemplateId, $this->userId, $this->importId);
            Excel::import($import, $fullFilePath, null, \Maatwebsite\Excel\Excel::CSV);

            // Clean up - commented out to keep files for debugging/testing
            // Storage::delete($this->filePath);

            // Update import status to completed (actual stats can be updated in the import class if needed)
            if ($this->importId) {
                DB::table('form_imports')->where('id', $this->importId)->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                ]);
            }

            Log::info('Form import completed', [
                'template_id' => $this->formTemplateId,
                'user_id' => $this->userId
            ]);

        } catch (\Exception $e) {
            Log::error('Form import job failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $this->userId
            ]);

            // Update import status to failed
            if ($this->importId) {
                DB::table('form_imports')->where('id', $this->importId)->update([
                    'status' => 'failed',
                    'completed_at' => now(),
                    'errors' => json_encode([['error' => $e->getMessage()]]),
                ]);
            }

            // Delete the uploaded file - commented out to keep files for debugging/testing
            // if (Storage::exists($this->filePath)) {
            //     Storage::delete($this->filePath);
            // }

            throw $e;
        }
    }

    /**
     * Process a chunk of rows
     */
    protected function processChunk(array $chunkRows, $formTemplate, $startRowNumber)
    {
        $validSubmissions = [];
        $validResponses = [];
        $errors = [];
        $skipped = 0;
        
        foreach ($chunkRows as $index => $row) {
            $rowNumber = $startRowNumber + $index;
            
            try {
                $rowResponses = [];
                
                // Validate all fields for this row
                foreach ($formTemplate->fields as $field) {
                    $fieldLabel = strtolower(str_replace(' ', '_', $field->label));
                    $value = $row[$fieldLabel] ?? null;

                    // Validate required fields
                    if ($field->is_required && (empty($value) && $value !== '0')) {
                        throw new \Exception("Required field '{$field->label}' is missing");
                    }

                    // Validate field type
                    if (!empty($value) || $value === '0') {
                        $this->validateFieldValue($field, $value, $rowNumber);
                    }

                    // Store response data
                    $rowResponses[$field->id] = $value;
                }

                // If validation passed, add to batch
                $validSubmissions[] = [
                    'form_template_id' => $this->formTemplateId,
                    'user_id' => $this->userId,
                    'status' => FormSubmission::STATUS_SUBMITTED,
                    'submitted_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                $validResponses[] = $rowResponses;

            } catch (\Exception $e) {
                $skipped++;
                $errors[] = [
                    'row' => $rowNumber,
                    'error' => $e->getMessage()
                ];
            }
        }

        // Batch insert this chunk
        $imported = 0;
        if (!empty($validSubmissions)) {
            try {
                DB::beginTransaction();
                
                // Insert submissions
                DB::table('form_submissions')->insert($validSubmissions);
                
                // Get inserted submission IDs
                $insertedSubmissions = FormSubmission::where('form_template_id', $this->formTemplateId)
                    ->where('user_id', $this->userId)
                    ->latest()
                    ->limit(count($validSubmissions))
                    ->get();
                
                // Prepare responses for this chunk
                $batchResponses = [];
                foreach ($insertedSubmissions as $index => $submission) {
                    if (isset($validResponses[$index])) {
                        foreach ($validResponses[$index] as $fieldId => $value) {
                            $batchResponses[] = [
                                'form_submission_id' => $submission->id,
                                'form_field_id' => $fieldId,
                                'response_value' => $value,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ];
                        }
                    }
                }
                
                // Insert responses in sub-chunks of 500
                foreach (array_chunk($batchResponses, 500) as $responseChunk) {
                    DB::table('submission_responses')->insert($responseChunk);
                }
                
                DB::commit();
                $imported = count($validSubmissions);
                
            } catch (\Exception $e) {
                DB::rollBack();
                $skipped += count($validSubmissions);
                $errors[] = [
                    'row' => 'chunk_' . $startRowNumber,
                    'error' => 'Chunk insert failed: ' . $e->getMessage()
                ];
                
                Log::error('Chunk import failed', [
                    'error' => $e->getMessage(),
                    'user_id' => $this->userId
                ]);
            }
        }
        
        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors
        ];
    }

    /**
     * Validate field value based on field type and validation rules
     */
    protected function validateFieldValue($field, $value, $rowNumber)
    {
        switch ($field->field_type) {
            case 'email':
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    throw new \Exception("Invalid email format in '{$field->label}'");
                }
                break;
            
            case 'number':
                if (!is_numeric($value)) {
                    throw new \Exception("Invalid number format in '{$field->label}'");
                }
                break;
            
            case 'date':
                try {
                    $date = \Carbon\Carbon::parse($value);
                } catch (\Exception $e) {
                    throw new \Exception("Invalid date format in '{$field->label}'");
                }
                break;
            
            case 'dropdown':
            case 'radio':
                if (!empty($field->options) && !in_array($value, $field->options)) {
                    throw new \Exception("Invalid option '{$value}' for '{$field->label}'. Allowed: " . implode(', ', $field->options));
                }
                break;
        }

        // Validate custom validation rules
        if (!empty($field->validation_rules)) {
            $this->applyValidationRules($field, $value, $rowNumber);
        }
    }

    /**
     * Apply validation rules from field configuration
     */
    protected function applyValidationRules($field, $value, $rowNumber)
    {
        $rules = is_string($field->validation_rules) 
            ? json_decode($field->validation_rules, true) 
            : $field->validation_rules;

        if (!is_array($rules)) {
            return;
        }

        // Min validation
        if (isset($rules['min'])) {
            $min = $rules['min'];
            if ($field->field_type === 'number') {
                if ((float)$value < (float)$min) {
                    throw new \Exception("'{$field->label}' must be at least {$min}. Got: {$value}");
                }
            } elseif (in_array($field->field_type, ['text', 'textarea'])) {
                if (strlen($value) < (int)$min) {
                    throw new \Exception("'{$field->label}' must be at least {$min} characters");
                }
            }
        }

        // Max validation
        if (isset($rules['max'])) {
            $max = $rules['max'];
            if ($field->field_type === 'number') {
                if ((float)$value > (float)$max) {
                    throw new \Exception("'{$field->label}' must not exceed {$max}. Got: {$value}");
                }
            } elseif (in_array($field->field_type, ['text', 'textarea'])) {
                if (strlen($value) > (int)$max) {
                    throw new \Exception("'{$field->label}' must not exceed {$max} characters");
                }
            }
        }

        // Regex validation
        if (isset($rules['regex']) && !empty($rules['regex'])) {
            if (!preg_match($rules['regex'], $value)) {
                throw new \Exception("'{$field->label}' format is invalid");
            }
        }

        // Min length validation
        if (isset($rules['min_length']) && strlen($value) < (int)$rules['min_length']) {
            throw new \Exception("'{$field->label}' must be at least {$rules['min_length']} characters");
        }

        // Max length validation
        if (isset($rules['max_length']) && strlen($value) > (int)$rules['max_length']) {
            throw new \Exception("'{$field->label}' must not exceed {$rules['max_length']} characters");
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception)
    {
        Log::error('Form import job permanently failed', [
            'file' => $this->filePath,
            'template_id' => $this->formTemplateId,
            'user_id' => $this->userId,
            'error' => $exception->getMessage()
        ]);

        // Update import status to failed
        if ($this->importId) {
            DB::table('form_imports')->where('id', $this->importId)->update([
                'status' => 'failed',
                'completed_at' => now(),
                'errors' => json_encode([['error' => $exception->getMessage()]]),
            ]);
        }

        // Delete the uploaded file - commented out to keep files for debugging/testing
        // if (Storage::exists($this->filePath)) {
        //     Storage::delete($this->filePath);
        // }
    }
}
