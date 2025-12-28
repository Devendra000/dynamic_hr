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
    public $tries = 1;

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
        ini_set('memory_limit', '512M');

        try {
            // Check if this import has already been processed to prevent duplicate processing
            if ($this->importId && DB::table('form_imports')->where('id', $this->importId)->where('total_rows', '>', 0)->exists()) {
                Log::info('Import already processed, skipping', ['import_id' => $this->importId]);
                return;
            }

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

            // Read file (XLSX or CSV) and split into chunks
            $fullFilePath = Storage::path($this->filePath);
            
            // Debug: Check if file exists
            if (!file_exists($fullFilePath)) {
                throw new \Exception("File does not exist at path: {$fullFilePath}");
            }
            
            Log::info('File exists, reading for chunking', [
                'file_path' => $this->filePath,
                'full_path' => $fullFilePath,
                'file_size' => filesize($fullFilePath)
            ]);
            
            // Fix #3: CSV conversion first (VERY EFFECTIVE) - Convert XLSX → CSV for better UTF-8 handling
            $fileExtension = strtolower(pathinfo($this->filePath, PATHINFO_EXTENSION));
            $processedFilePath = $fullFilePath;

            if ($fileExtension === 'xlsx') {
                // Convert XLSX to CSV for better UTF-8 normalization
                $csvPath = Storage::path('temp/' . uniqid() . '_converted.csv');
                try {
                    Excel::store(new \Maatwebsite\Excel\Concerns\FromArray([]), 'temp/temp.csv', 'local');
                    Excel::convert($fullFilePath, $csvPath, \Maatwebsite\Excel\Excel::XLSX, \Maatwebsite\Excel\Excel::CSV);
                    $processedFilePath = $csvPath;
                    Log::info('Converted XLSX to CSV for better UTF-8 handling', ['original' => $fullFilePath, 'converted' => $csvPath]);
                } catch (\Exception $e) {
                    Log::warning('XLSX to CSV conversion failed, proceeding with original file', [
                        'error' => $e->getMessage(),
                        'file' => $fullFilePath
                    ]);
                    // Fall back to original file if conversion fails
                }
            }

            // Read the file using Excel package (handles both XLSX and CSV)
            $data = Excel::toArray([], $processedFilePath); // Don't use HeadingRowImport for CSV
            $rows = $data[0] ?? [];
            
            Log::info('Excel data read', [
                'data_count' => count($data),
                'rows_count' => count($rows),
                'first_row_keys' => !empty($rows) ? array_keys($rows[0] ?? []) : null,
                'first_row_sample' => !empty($rows) ? array_slice($rows[0] ?? [], 0, 3) : null
            ]);
            
            // Handle headers manually for CSV
            if (!empty($rows)) {
                $headers = array_shift($rows); // Remove first row as headers
                $rows = array_map(function ($row) use ($headers) {
                    return array_combine($headers, $row);
                }, $rows);
            }
            
            // Remove header row if present and ensure UTF-8 encoding
            if (!empty($rows) && is_array($rows[0])) {
                $firstRow = $rows[0];
                $hasHeader = false;
                foreach ($firstRow as $key => $value) {
                    if (is_string($key) && !is_numeric($key)) {
                        $hasHeader = true;
                        break;
                    }
                }
                if (!$hasHeader) {
                    array_shift($rows); // Remove header row
                }
            }
            
            // Ensure all data is UTF-8 encoded
            $rows = $this->cleanImportData($rows);
            
            $totalRows = count($rows);
            
            // Update total_rows in import
            if ($this->importId) {
                DB::table('form_imports')->where('id', $this->importId)->update([
                    'total_rows' => $totalRows,
                ]);
            }
            
            // Split into chunks of 10,000 rows
            $chunks = array_chunk($rows, 10000);
            
            Log::info('Dispatching chunk jobs', ['total_chunks' => count($chunks)]);
            
            foreach ($chunks as $chunk) {
                \App\Jobs\ProcessFormImportChunk::dispatch($this->formTemplateId, $this->userId, $this->importId, $chunk);
            }

            // Clean up temporary CSV file if it was created
            if (isset($csvPath) && $csvPath !== $fullFilePath && file_exists($csvPath)) {
                unlink($csvPath);
                Log::info('Cleaned up temporary CSV file', ['file' => $csvPath]);
            }

        } catch (\Exception $e) {
            Log::error('Form import job failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $this->userId
            ]);

            // Update import status to failed
            if ($this->importId) {
                // Ensure error message is UTF-8 encoded
                $errorMessage = $this->cleanUtf8($e->getMessage());
                $errorMessage = $this->stripInvisibleChars($errorMessage);

                DB::table('form_imports')->where('id', $this->importId)->update([
                    'status' => 'failed',
                    'completed_at' => now(),
                    'errors' => json_encode([['error' => $errorMessage]], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
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
            // Ensure error message is UTF-8 encoded
            $errorMessage = $this->cleanUtf8($exception->getMessage());
            $errorMessage = $this->stripInvisibleChars($errorMessage);

            DB::table('form_imports')->where('id', $this->importId)->update([
                'status' => 'failed',
                'completed_at' => now(),
                'errors' => json_encode([['error' => $errorMessage]], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            ]);
        }

        // Delete the uploaded file - commented out to keep files for debugging/testing
        // if (Storage::exists($this->filePath)) {
        //     Storage::delete($this->filePath);
        // }
    }

    /**
     * Comprehensive data cleaning for Excel imports (multiple UTF-8 fixes)
     */
    protected function cleanImportData(array $rows): array
    {
        return array_map(function ($row) {
            if (!is_array($row)) {
                return $row;
            }

            $cleanedRow = [];
            foreach ($row as $key => $value) {
                // Clean key (column header)
                if (is_string($key)) {
                    $key = $this->cleanUtf8($key);
                    $key = $this->stripInvisibleChars($key);
                }

                // Clean value (cell data)
                if (is_string($value)) {
                    $value = $this->cleanUtf8($value);
                    $value = $this->stripInvisibleChars($value);
                }

                $cleanedRow[$key] = $value;
            }
            return $cleanedRow;
        }, $rows);
    }

    /**
     * Fix #1: Force UTF-8 cleaning (RECOMMENDED)
     */
    protected function cleanUtf8($value): string
    {
        if (!is_string($value)) {
            return $value;
        }

        // Detect encoding and convert to UTF-8
        $detectedEncoding = mb_detect_encoding($value, ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'ASCII'], true);
        if ($detectedEncoding && $detectedEncoding !== 'UTF-8') {
            $value = mb_convert_encoding($value, 'UTF-8', $detectedEncoding);
        }

        // Ensure it's valid UTF-8
        $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');

        // Remove invalid UTF-8 sequences
        $value = iconv('UTF-8', 'UTF-8//IGNORE', $value);

        return $value;
    }

    /**
     * Fix #2: Strip invisible characters
     */
    protected function stripInvisibleChars(string $value): string
    {
        // Remove control characters (0x00-0x1F and 0x7F-0x9F) but keep newlines and tabs
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x9F]/u', '', $value);

        // Remove zero-width characters and other invisible Unicode
        $value = preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{FEFF}]/u', '', $value);

        return $value;
    }
}
