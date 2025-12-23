<?php

namespace App\Exports;

use App\Models\FormSubmission;
use App\Models\FormTemplate;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Illuminate\Support\Collection;

class FormSubmissionsExport implements FromCollection, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
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
     * Get the submissions collection
     */
    public function collection()
    {
        $query = FormSubmission::with(['template.fields', 'user', 'responses.field', 'reviewer'])
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
            ->latest()
            ->get();

        return $query;
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
     * Apply styles to the worksheet
     */
    public function styles(Worksheet $sheet)
    {
        return [
            1 => [
                'font' => [
                    'bold' => true,
                    'size' => 12,
                ],
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '4472C4'],
                ],
                'font' => [
                    'color' => ['rgb' => 'FFFFFF'],
                    'bold' => true,
                ],
            ],
        ];
    }
}
