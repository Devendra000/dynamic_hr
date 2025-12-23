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
     * Process the collection with batch inserts for performance
     */
    public function collection(Collection $rows)
    {
        // Validate all rows first and prepare data
        $validSubmissions = [];
        $validResponses = [];
        
        foreach ($rows as $index => $row) {
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

        // Batch insert submissions
        if (!empty($validSubmissions)) {
            try {
                DB::beginTransaction();
                
                // Insert all submissions at once
                DB::table('form_submissions')->insert($validSubmissions);
                
                // Get the IDs of inserted submissions
                $insertedSubmissions = FormSubmission::where('form_template_id', $this->formTemplateId)
                    ->where('user_id', $this->userId)
                    ->latest()
                    ->limit(count($validSubmissions))
                    ->get();
                
                // Prepare batch responses
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
                
                // Batch insert responses in chunks of 500
                foreach (array_chunk($batchResponses, 500) as $chunk) {
                    DB::table('submission_responses')->insert($chunk);
                }
                
                DB::commit();
                $this->imported = count($validSubmissions);
                
                Log::info('Batch import successful', [
                    'imported' => $this->imported,
                    'user_id' => $this->userId
                ]);
                
            } catch (\Exception $e) {
                DB::rollBack();
                $this->skipped += count($validSubmissions);
                $this->errors[] = [
                    'row' => 'batch',
                    'error' => 'Batch insert failed: ' . $e->getMessage()
                ];
                
                Log::error('Batch import failed', [
                    'error' => $e->getMessage(),
                    'user_id' => $this->userId
                ]);
            }
        }
    }

    /**
     * Validate field value based on field type
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
