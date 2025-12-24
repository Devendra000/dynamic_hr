<?php

namespace App\Imports;

use App\Models\FormTemplate;
use App\Models\FormSubmission;
use App\Models\SubmissionResponse;
use App\Models\User;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Concerns\SkipsOnError;
use Maatwebsite\Excel\Concerns\SkipsErrors;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FormSubmissionsImport implements ToCollection, WithHeadingRow, WithValidation, SkipsOnError
{
    use SkipsErrors;

    protected $formTemplateId;
    protected $userId;
    protected $formTemplate;
    protected $errors = [];
    protected $imported = 0;
    protected $skipped = 0;

    public function __construct($formTemplateId, $userId)
    {
        $this->formTemplateId = $formTemplateId;
        $this->userId = $userId;
        $this->formTemplate = FormTemplate::with('fields')->findOrFail($formTemplateId);
    }

    /**
     * Process the collection in chunks for memory efficiency
     */
    public function collection(Collection $rows)
    {
        $chunkSize = 500; // Process 500 rows at a time
        $chunks = $rows->chunk($chunkSize);
        
        foreach ($chunks as $chunkRows) {
            $this->processChunk($chunkRows);
        }
    }
    
    /**
     * Process a chunk of rows
     */
    protected function processChunk(Collection $chunkRows)
    {
        // Validate chunk and prepare data
        $validSubmissions = [];
        $validResponses = [];
        
        foreach ($chunkRows as $index => $row) {
            $rowNumber = $index + 2; // +2 because of header and 0-index
            
            try {
                $rowResponses = [];
                
                // Validate all fields for this row
                foreach ($this->formTemplate->fields as $field) {
                    $fieldLabel = strtolower(str_replace(' ', '_', $field->label));
                    $value = $row[$fieldLabel] ?? null;

                    // Validate required fields
                    if ($field->is_required && (empty($value) && $value !== '0')) {
                        throw new \Exception("Required field '{$field->label}' is missing in row {$rowNumber}");
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
                $this->skipped++;
                $this->errors[] = [
                    'row' => $rowNumber,
                    'error' => $e->getMessage()
                ];
                
                Log::error('Excel import validation error', [
                    'row' => $rowNumber,
                    'error' => $e->getMessage(),
                    'user_id' => $this->userId
                ]);
            }
        }

        // Batch insert this chunk
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
                $this->imported += count($validSubmissions);
                
            } catch (\Exception $e) {
                DB::rollBack();
                $this->skipped += count($validSubmissions);
                $this->errors[] = [
                    'row' => 'chunk',
                    'error' => 'Chunk insert failed: ' . $e->getMessage()
                ];
                
                Log::error('Chunk import failed', [
                    'error' => $e->getMessage(),
                    'user_id' => $this->userId
                ]);
            }
        }
        
        // Free memory after each chunk
        unset($validSubmissions, $validResponses, $batchResponses);
        gc_collect_cycles();
    }

    /**
     * Validate field value based on field type and validation rules
     */
    protected function validateFieldValue($field, $value, $rowNumber)
    {
        switch ($field->field_type) {
            case 'email':
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    throw new \Exception("Invalid email format in '{$field->label}' at row {$rowNumber}");
                }
                break;
            
            case 'number':
                if (!is_numeric($value)) {
                    throw new \Exception("Invalid number format in '{$field->label}' at row {$rowNumber}");
                }
                break;
            
            case 'date':
                try {
                    $date = \Carbon\Carbon::parse($value);
                } catch (\Exception $e) {
                    throw new \Exception("Invalid date format in '{$field->label}' at row {$rowNumber}");
                }
                break;
            
            case 'dropdown':
            case 'radio':
                if (!empty($field->options) && !in_array($value, $field->options)) {
                    throw new \Exception("Invalid option '{$value}' for '{$field->label}' at row {$rowNumber}. Allowed: " . implode(', ', $field->options));
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

        // Min validation (for numbers, text length, etc.)
        if (isset($rules['min'])) {
            $min = $rules['min'];
            if ($field->field_type === 'number') {
                if ((float)$value < (float)$min) {
                    throw new \Exception("'{$field->label}' must be at least {$min} at row {$rowNumber}. Got: {$value}");
                }
            } elseif (in_array($field->field_type, ['text', 'textarea'])) {
                if (strlen($value) < (int)$min) {
                    throw new \Exception("'{$field->label}' must be at least {$min} characters at row {$rowNumber}");
                }
            }
        }

        // Max validation (for numbers, text length, etc.)
        if (isset($rules['max'])) {
            $max = $rules['max'];
            if ($field->field_type === 'number') {
                if ((float)$value > (float)$max) {
                    throw new \Exception("'{$field->label}' must not exceed {$max} at row {$rowNumber}. Got: {$value}");
                }
            } elseif (in_array($field->field_type, ['text', 'textarea'])) {
                if (strlen($value) > (int)$max) {
                    throw new \Exception("'{$field->label}' must not exceed {$max} characters at row {$rowNumber}");
                }
            }
        }

        // Regex validation
        if (isset($rules['regex']) && !empty($rules['regex'])) {
            if (!preg_match($rules['regex'], $value)) {
                throw new \Exception("'{$field->label}' format is invalid at row {$rowNumber}");
            }
        }

        // Min length validation (alternative key)
        if (isset($rules['min_length']) && strlen($value) < (int)$rules['min_length']) {
            throw new \Exception("'{$field->label}' must be at least {$rules['min_length']} characters at row {$rowNumber}");
        }

        // Max length validation (alternative key)
        if (isset($rules['max_length']) && strlen($value) > (int)$rules['max_length']) {
            throw new \Exception("'{$field->label}' must not exceed {$rules['max_length']} characters at row {$rowNumber}");
        }
    }

    /**
     * Define validation rules
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * Get import statistics
     */
    public function getStats()
    {
        return [
            'imported' => $this->imported,
            'skipped' => $this->skipped,
            'errors' => $this->errors
        ];
    }

    /**
     * Heading row index
     */
    public function headingRow(): int
    {
        return 1;
    }
}
