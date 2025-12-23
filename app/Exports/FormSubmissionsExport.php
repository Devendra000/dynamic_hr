<?php

namespace App\Exports;

use App\Models\FormSubmission;
use App\Models\FormTemplate;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Illuminate\Support\Collection;

class FormSubmissionsExport implements FromQuery, WithHeadings, WithMapping, WithStyles, ShouldAutoSize, WithChunkReading
{
    protected $formTemplateId;
    protected $status;
    protected $userId;
    protected $dateFrom;
    protected $dateTo;
    protected $formTemplate;

    public function __construct($filters = [])
    {
        $this->formTemplateId = $filters['form_template_id'] ?? null;
        $this->status = $filters['status'] ?? null;
        $this->userId = $filters['user_id'] ?? null;
        $this->dateFrom = $filters['date_from'] ?? null;
        $this->dateTo = $filters['date_to'] ?? null;

        if ($this->formTemplateId) {
            $this->formTemplate = FormTemplate::with('fields')->find($this->formTemplateId);
        }
    }

    /**
     * Return a query for chunk processing (memory efficient)
     */
    public function query()
    {
        return FormSubmission::query()
            ->with(['template.fields', 'user', 'responses.field', 'reviewer'])
            ->when($this->formTemplateId, function ($q) {
                return $q->where('form_template_id', $this->formTemplateId);
            })
            ->when($this->status, function ($q) {
                return $q->where('status', $this->status);
            })
            ->when($this->userId, function ($q) {
                return $q->where('user_id', $this->userId);
            })
            ->when($this->dateFrom, function ($q) {
                return $q->whereDate('created_at', '>=', $this->dateFrom);
            })
            ->when($this->dateTo, function ($q) {
                return $q->whereDate('created_at', '<=', $this->dateTo);
            })
            ->latest();
    }

    /**
     * Set chunk size for reading (processes 1000 rows at a time)
     */
    public function chunkSize(): int
    {
        return 1000;
    }

    /**
     * Define the headings for the Excel file
     */
    public function headings(): array
    {
        $baseHeadings = [
            'Submission ID',
            'Form Template',
            'Employee Name',
            'Employee Email',
            'Department',
            'Position',
            'Status',
            'Submitted At',
            'Reviewed By',
            'Reviewed At',
            'Comments',
        ];

        // Add dynamic field headings if specific template
        if ($this->formTemplate) {
            $fieldHeadings = $this->formTemplate->fields->sortBy('order')->pluck('label')->toArray();
            return array_merge($baseHeadings, $fieldHeadings);
        }

        return $baseHeadings;
    }

    /**
     * Map the data for each row
     */
    public function map($submission): array
    {
        $baseData = [
            $submission->id,
            $submission->template->title,
            $submission->user->name,
            $submission->user->email,
            $submission->user->department ?? 'N/A',
            $submission->user->position ?? 'N/A',
            ucfirst($submission->status),
            $submission->submitted_at ? $submission->submitted_at->format('Y-m-d H:i:s') : 'Not submitted',
            $submission->reviewer ? $submission->reviewer->name : 'N/A',
            $submission->reviewed_at ? $submission->reviewed_at->format('Y-m-d H:i:s') : 'Not reviewed',
            $submission->comments ?? 'N/A',
        ];

        // Add dynamic field responses if specific template
        if ($this->formTemplate) {
            $fieldResponses = [];
            foreach ($this->formTemplate->fields->sortBy('order') as $field) {
                $response = $submission->responses->firstWhere('form_field_id', $field->id);
                $fieldResponses[] = $response ? $response->response_value : '';
            }
            return array_merge($baseData, $fieldResponses);
        }

        return $baseData;
    }

    /**
     * Apply styles and validation to the worksheet
     */
    public function styles(Worksheet $sheet)
    {
        // Style the header row
        $sheet->getStyle('1:1')->applyFromArray([
            'font' => [
                'bold' => true,
                'size' => 12,
                'color' => ['rgb' => 'FFFFFF'],
            ],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => '4472C4'],
            ],
        ]);

        // Add dropdown validation for fields with options (if specific template)
        if ($this->formTemplate) {
            $baseColumns = 11; // Number of base columns before dynamic fields
            
            foreach ($this->formTemplate->fields->sortBy('order') as $index => $field) {
                $columnIndex = $baseColumns + $index + 1;
                $column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnIndex);
                
                // Add dropdown validation for dropdown/radio fields
                if (in_array($field->field_type, ['dropdown', 'radio']) && !empty($field->options)) {
                    // Apply validation from row 2 to 1000
                    for ($row = 2; $row <= 1000; $row++) {
                        $validation = $sheet->getCell($column . $row)->getDataValidation();
                        $validation->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST);
                        $validation->setErrorStyle(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::STYLE_INFORMATION);
                        $validation->setAllowBlank(true);
                        $validation->setShowInputMessage(true);
                        $validation->setShowErrorMessage(true);
                        $validation->setShowDropDown(true);
                        $validation->setErrorTitle('Invalid Input');
                        $validation->setError('Please select from the dropdown');
                        $validation->setPromptTitle('Select Option');
                        $validation->setPrompt('Choose from available options');
                        $validation->setFormula1('"' . implode(',', $field->options) . '"');
                    }
                }
            }
        }

        return [];
    }
}
