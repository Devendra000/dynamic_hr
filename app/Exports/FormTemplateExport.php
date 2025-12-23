<?php

namespace App\Exports;

use App\Models\FormTemplate;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class FormTemplateExport implements FromArray, WithHeadings, WithStyles, ShouldAutoSize
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

    /**
     * Apply styles to the worksheet
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

        // Style the instruction row
        $sheet->getStyle('2:2')->applyFromArray([
            'font' => [
                'italic' => true,
                'color' => ['rgb' => '808080'],
            ],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'FFF2CC'],
            ],
        ]);

        // Add dropdown validation for dropdown/radio fields
        foreach ($this->formTemplate->fields->sortBy('order') as $index => $field) {
            $column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
            
            // Add dropdown validation for dropdown/radio fields
            if (in_array($field->field_type, ['dropdown', 'radio']) && !empty($field->options)) {
                $validation = $sheet->getCell($column . '3')->getDataValidation();
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
                
                // Copy validation to more rows
                for ($row = 4; $row <= 100; $row++) {
                    $sheet->getCell($column . $row)->setDataValidation(clone $validation);
                }
            }
        }

        return [];
    }
}
