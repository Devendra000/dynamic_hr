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
        $formTemplate = FormTemplate::find($this->formTemplateId);
        if (!$formTemplate) {
            Log::error('Form template not found', ['id' => $this->formTemplateId]);
            return;
        }

        $imported = 0;
        $skipped = 0;

        foreach ($this->rows as $row) {
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
                        continue 2; // Skip this row
                    }

                    if (!empty($value) || $value === '0') {
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
                }
            } catch (\Exception $e) {
                Log::error('Error processing row in chunk', [
                    'error' => $e->getMessage(),
                    'user_id' => $this->userId
                ]);
                $skipped++;
            }
        }

        // Update import counts
        if ($this->importId) {
            DB::table('form_imports')->where('id', $this->importId)->increment('imported_count', $imported);
            DB::table('form_imports')->where('id', $this->importId)->increment('skipped_count', $skipped);
            
            // Check if import is complete
            $import = DB::table('form_imports')->where('id', $this->importId)->first();
            if ($import && ($import->imported_count + $import->skipped_count) >= $import->total_rows) {
                DB::table('form_imports')->where('id', $this->importId)->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                ]);
            }
        }
    }
}
