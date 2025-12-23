<?php

namespace App\Exports;

use App\Models\FormTemplate;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class FormTemplateExport implements FromArray, WithHeadings, ShouldAutoSize
{
    protected $formTemplate;

    public function __construct($formTemplateId)
    {
        $this->formTemplate = FormTemplate::with('fields')->findOrFail($formTemplateId);
    }

    /**
     * Return array of sample data
     */
    public function array(): array
    {
        // Return 3 sample rows with instructions
        $sampleRows = [];
        
        // Add instruction row
        $instructionRow = ['>>> Fill in your data below this row. Keep the headers unchanged. <<<'];
        $sampleRows[] = $instructionRow;
        
        // Add 2 sample data rows
        for ($i = 1; $i <= 2; $i++) {
            $row = [];
            foreach ($this->formTemplate->fields->sortBy('order') as $field) {
                $row[] = $this->getSampleValue($field, $i);
            }
            $sampleRows[] = $row;
        }

        return $sampleRows;
    }

    /**
     * Generate sample values based on field type
     */
    protected function getSampleValue($field, $rowNumber)
    {
        switch ($field->field_type) {
            case 'text':
                return "Sample {$field->label} {$rowNumber}";
            case 'textarea':
                return "Sample long text for {$field->label}...";
            case 'number':
                return $rowNumber * 10;
            case 'email':
                return "user{$rowNumber}@example.com";
            case 'date':
                return date('Y-m-d');
            case 'dropdown':
            case 'radio':
                $options = $field->options ?? [];
                return !empty($options) ? $options[0] : 'Option 1';
            case 'checkbox':
                $options = $field->options ?? [];
                return !empty($options) ? implode(', ', array_slice($options, 0, 2)) : 'Option 1, Option 2';
            case 'file':
                return 'file_path.pdf';
            default:
                return 'Sample value';
        }
    }

    /**
     * Define the headings (field labels)
     */
    public function headings(): array
    {
        return $this->formTemplate->fields->sortBy('order')->pluck('label')->toArray();
    }
}
