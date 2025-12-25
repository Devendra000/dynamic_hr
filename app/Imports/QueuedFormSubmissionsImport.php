<?php

namespace App\Imports;

use App\Models\FormTemplate;
use App\Models\FormSubmission;
use App\Models\SubmissionResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithBatchInserts;

class QueuedFormSubmissionsImport implements ToModel, WithHeadingRow, WithChunkReading, WithBatchInserts
{
    protected $formTemplateId;
    protected $userId;
    protected $importId;
    protected $formTemplate;

    public function __construct($formTemplateId, $userId, $importId = null)
    {
        $this->formTemplateId = $formTemplateId;
        $this->userId = $userId;
        $this->importId = $importId;
        $this->formTemplate = FormTemplate::with('fields')->findOrFail($formTemplateId);
    }

    public function model(array $row)
    {
        // Debug: Log the first row to see what keys are available
        static $debugLogged = false;
        if (!$debugLogged) {
            Log::info('CSV Row keys: ' . implode(', ', array_keys($row)), [
                'user_id' => $this->userId,
                'row_data' => $row
            ]);
            $debugLogged = true;
        }

        try {
            $rowResponses = [];
            foreach ($this->formTemplate->fields as $field) {
                $fieldLabel = strtolower(str_replace(' ', '_', $field->label));
                $value = $row[$fieldLabel] ?? null;

                if ($field->is_required && (empty($value) && $value !== '0')) {
                    Log::warning("Required field '{$field->label}' is missing, skipping row", [
                        'user_id' => $this->userId,
                        'field' => $field->label
                    ]);
                    return null; // Skip this row
                }

                // Validate value if present
                if (!empty($value) || $value === '0') {
                    $validationError = $this->validateFieldValue($field, $value);
                    if ($validationError) {
                        Log::warning("Validation failed for field '{$field->label}': {$validationError}, skipping row", [
                            'user_id' => $this->userId,
                            'field' => $field->label,
                            'value' => $value
                        ]);
                        return null; // Skip this row
                    }

                    $rowResponses[] = [
                        'form_field_id' => $field->id,
                        'response_value' => $value,
                    ];
                }
            }

            if (!empty($rowResponses)) {
                // Insert submission directly using DB to avoid ID conflicts
                $submissionId = DB::table('form_submissions')->insertGetId([
                    'form_template_id' => $this->formTemplateId,
                    'user_id' => $this->userId,
                    'status' => FormSubmission::STATUS_SUBMITTED,
                    'submitted_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Insert responses
                foreach ($rowResponses as &$response) {
                    $response['form_submission_id'] = $submissionId;
                    $response['created_at'] = now();
                    $response['updated_at'] = now();
                }

                DB::table('submission_responses')->insert($rowResponses);

                // Update import count
                if ($this->importId) {
                    DB::table('form_imports')->where('id', $this->importId)->increment('imported_count');
                }

                return null; // Don't return a model for ToModel batching
            }

            return null;

        } catch (\Exception $e) {
            Log::error('Error processing row', [
                'error' => $e->getMessage(),
                'user_id' => $this->userId
            ]);

            // Update skipped count
            if ($this->importId) {
                DB::table('form_imports')->where('id', $this->importId)->increment('skipped_count');
            }

            return null;
        }
    }

    /**
     * Validate a field value based on its type and validation rules.
     */
    private function validateFieldValue($field, $value)
    {
        $rules = $field->validation_rules ?? [];

        switch ($field->field_type) {
            case 'email':
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    return 'Invalid email format';
                }
                break;

            case 'number':
                if (!is_numeric($value)) {
                    return 'Must be a number';
                }
                $numValue = (float) $value;
                if (isset($rules['min']) && $numValue < $rules['min']) {
                    return "Must be at least {$rules['min']}";
                }
                if (isset($rules['max']) && $numValue > $rules['max']) {
                    return "Must be at most {$rules['max']}";
                }
                break;

            case 'date':
                try {
                    \Carbon\Carbon::parse($value);
                } catch (\Exception $e) {
                    return 'Invalid date format';
                }
                break;

            case 'text':
                if (isset($rules['min']) && strlen($value) < $rules['min']) {
                    return "Must be at least {$rules['min']} characters";
                }
                if (isset($rules['max']) && strlen($value) > $rules['max']) {
                    return "Must be at most {$rules['max']} characters";
                }
                break;

            case 'dropdown':
                if (!empty($field->options) && !in_array($value, $field->options)) {
                    return 'Value not in allowed options';
                }
                break;
        }

        return null; // No error
    }

    public function batchSize(): int
    {
        return 100; // Process 100 rows at a time
    }

    public function chunkSize(): int
    {
        return 500; // Read 500 rows at a time from file
    }
}
