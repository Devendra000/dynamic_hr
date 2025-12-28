<?php

namespace App\Jobs;

use App\Models\FormTemplate;
use App\Models\FormSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessFormImportChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $formTemplateId;
    protected $userId;
    protected $importId;
    protected $rows;

    /**
     * Number of times the job may be attempted.
     */
    public $tries = 3;

    /**
     * Number of seconds the job can run before timing out.
     */
    public $timeout = 300; // 5 minutes

    /**
     * Create a new job instance.
     */
    public function __construct($formTemplateId, $userId, $importId, $rows)
    {
        $this->formTemplateId = $formTemplateId;
        $this->userId = $userId;
        $this->importId = $importId;
        $this->rows = $rows;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        ini_set('memory_limit', '512M');

        $formTemplate = FormTemplate::find($this->formTemplateId);
        if (!$formTemplate) {
            Log::error('Form template not found', ['id' => $this->formTemplateId]);
            return;
        }

        $imported = 0;
        $skipped = 0;
        $batchSize = 1000;
        $batchImported = 0;
        $batchSkipped = 0;

        foreach ($this->rows as $row) {
            // Normalize row keys to match field labels
            $normalizedRow = [];
            foreach ($row as $key => $value) {
                $normalizedKey = strtolower(str_replace(' ', '_', $key));
                $normalizedRow[$normalizedKey] = $value;
            }
            $row = $normalizedRow;

            try {
                $rowResponses = [];
                foreach ($formTemplate->fields as $field) {
                    $fieldLabel = strtolower(str_replace(' ', '_', $field->label));
                    $value = $row[$fieldLabel] ?? null;

                    if ($field->is_required && (empty($value) && $value !== '0')) {
                        Log::warning("Required field '{$field->label}' is missing, skipping row", [
                            'user_id' => $this->userId,
                            'field' => $field->label
                        ]);
                        $skipped++;
                        $batchSkipped++;
                        continue 2; // Skip this row
                    }

                    if (!empty($value) || $value === '0') {
                        // Validate field value
                        $validationError = $this->validateFieldValue($field, $value);
                        if ($validationError) {
                            Log::warning("Validation failed for field '{$field->label}': {$validationError}, skipping row", [
                                'user_id' => $this->userId,
                                'field' => $field->label,
                                'value' => $value
                            ]);
                            $skipped++;
                            $batchSkipped++;
                            continue 2; // Skip this row
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

                    $imported++;
                    $batchImported++;
                }
            } catch (\Exception $e) {
                Log::error('Error processing row in chunk', [
                    'error' => $e->getMessage(),
                    'user_id' => $this->userId
                ]);
                $skipped++;
                $batchSkipped++;
            }

            // Update batch counts if threshold reached
            if ($batchImported + $batchSkipped >= $batchSize) {
                $import = DB::table('form_imports')->where('id', $this->importId)->first();
                if ($import) {
                    DB::table('form_imports')->where('id', $this->importId)->update([
                        'imported_count' => $import->imported_count + $batchImported,
                        'skipped_count' => $import->skipped_count + $batchSkipped,
                    ]);
                }
                $batchImported = 0;
                $batchSkipped = 0;
            }
        }

        // Update remaining batch counts
        if ($batchImported + $batchSkipped > 0) {
            $import = DB::table('form_imports')->where('id', $this->importId)->first();
            if ($import) {
                DB::table('form_imports')->where('id', $this->importId)->update([
                    'imported_count' => $import->imported_count + $batchImported,
                    'skipped_count' => $import->skipped_count + $batchSkipped,
                ]);
            }
        }

        // Update import counts
        if ($this->importId) {
            Log::info('Updated import counts', [
                'import_id' => $this->importId,
                'imported' => $imported,
                'skipped' => $skipped
            ]);
            
            // Check if import is complete
            $import = DB::table('form_imports')->where('id', $this->importId)->first();
            if ($import && ($import->imported_count + $import->skipped_count) >= $import->total_rows) {
                DB::table('form_imports')->where('id', $this->importId)->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                ]);
            }
        }

        // Check if import is complete and update status
        if ($this->importId) {
            $import = DB::table('form_imports')->where('id', $this->importId)->first();
            if ($import && ($import->imported_count + $import->skipped_count) >= $import->total_rows) {
                DB::table('form_imports')->where('id', $this->importId)->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                ]);
                Log::info('Import completed', ['import_id' => $this->importId]);
            }
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
            case 'radio':
                if (!empty($field->options) && !in_array($value, $field->options)) {
                    return 'Value not in allowed options';
                }
                break;
        }

        return null; // No error
    }
}
