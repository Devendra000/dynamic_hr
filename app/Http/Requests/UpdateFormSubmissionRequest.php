<?php

namespace App\Http\Requests;

use App\Models\FormSubmission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateFormSubmissionRequest extends FormRequest
{
    protected $submission;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $rules = [
            'status' => 'sometimes|in:draft,submitted',
            'responses' => 'sometimes|array',
        ];

        // Load the submission with template and fields
        $submissionId = $this->route('id');
        $this->submission = FormSubmission::with(['template.fields', 'responses'])->find($submissionId);

        if ($this->submission && $this->has('responses')) {
            // If changing status to submitted, validate required fields
            $isSubmitting = $this->input('status') === 'submitted';
            
            if ($isSubmitting) {
                // Build current state of responses
                $currentResponses = [];
                foreach ($this->submission->responses as $response) {
                    $currentResponses[$response->form_field_id] = $response->response_value;
                }
                
                // Merge with new responses
                foreach ($this->input('responses', []) as $fieldId => $value) {
                    $currentResponses[$fieldId] = $value;
                }

                // Validate each field
                foreach ($this->submission->template->fields as $field) {
                    $fieldKey = "responses.{$field->id}";
                    $fieldRules = [];

                    // Check if this field is being updated or exists
                    $value = $currentResponses[$field->id] ?? null;

                    // Required validation
                    if ($field->is_required && (empty($value) && $value !== '0')) {
                        throw new HttpResponseException(response()->json([
                            'success' => false,
                            'message' => "The field '{$field->label}' is required when submitting",
                            'errors' => [
                                $fieldKey => ["The field '{$field->label}' is required"]
                            ]
                        ], 422));
                    }

                    // Type-specific validation for fields being updated
                    if ($this->has("responses.{$field->id}")) {
                        switch ($field->field_type) {
                            case 'email':
                                $fieldRules[] = 'email';
                                break;
                            
                            case 'number':
                                $fieldRules[] = 'numeric';
                                // Add min/max validation if specified in validation_rules JSON
                                if (isset($field->validation_rules['min'])) {
                                    $fieldRules[] = 'min:' . $field->validation_rules['min'];
                                }
                                if (isset($field->validation_rules['max'])) {
                                    $fieldRules[] = 'max:' . $field->validation_rules['max'];
                                }
                                break;
                            
                            case 'date':
                                $fieldRules[] = 'date';
                                break;
                            
                            case 'dropdown':
                            case 'radio':
                                if (!empty($field->options)) {
                                    $fieldRules[] = 'in:' . implode(',', $field->options);
                                }
                                break;
                            
                            case 'text':
                            case 'textarea':
                                $fieldRules[] = 'string';
                                if ($field->field_type === 'text') {
                                    $fieldRules[] = 'max:255';
                                }
                                break;
                        }

                        if (!empty($fieldRules)) {
                            $rules[$fieldKey] = 'nullable|' . implode('|', $fieldRules);
                        }
                    }
                }
            } else {
                // For draft updates, just validate types of provided fields
                foreach ($this->submission->template->fields as $field) {
                    if ($this->has("responses.{$field->id}")) {
                        $fieldKey = "responses.{$field->id}";
                        $fieldRules = ['nullable'];

                        switch ($field->field_type) {
                            case 'email':
                                $fieldRules[] = 'email';
                                break;
                            case 'number':
                                $fieldRules[] = 'numeric';
                                // Add min/max validation if specified in validation_rules JSON
                                if (isset($field->validation_rules['min'])) {
                                    $fieldRules[] = 'min:' . $field->validation_rules['min'];
                                }
                                if (isset($field->validation_rules['max'])) {
                                    $fieldRules[] = 'max:' . $field->validation_rules['max'];
                                }
                                break;
                            case 'date':
                                $fieldRules[] = 'date';
                                break;
                            case 'dropdown':
                            case 'radio':
                                if (!empty($field->options)) {
                                    $fieldRules[] = 'in:' . implode(',', $field->options);
                                }
                                break;
                            case 'text':
                            case 'textarea':
                                $fieldRules[] = 'string';
                                break;
                        }

                        $rules[$fieldKey] = implode('|', $fieldRules);
                    }
                }
            }
        }

        return $rules;
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        $messages = [
            'status.in' => 'Status must be either draft or submitted',
            'responses.array' => 'Form responses must be an array',
        ];

        if ($this->submission) {
            foreach ($this->submission->template->fields as $field) {
                $fieldKey = "responses.{$field->id}";
                $messages["{$fieldKey}.required"] = "The field '{$field->label}' is required";
                $messages["{$fieldKey}.email"] = "The field '{$field->label}' must be a valid email address";
                $messages["{$fieldKey}.numeric"] = "The field '{$field->label}' must be a number";
                $messages["{$fieldKey}.date"] = "The field '{$field->label}' must be a valid date";
                $messages["{$fieldKey}.in"] = "The field '{$field->label}' has an invalid option";
                $messages["{$fieldKey}.string"] = "The field '{$field->label}' must be text";
                $messages["{$fieldKey}.max"] = "The field '{$field->label}' must not exceed 255 characters";
            }
        }

        return $messages;
    }

    /**
     * Handle a failed validation attempt.
     */
    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $validator->errors()
        ], 422));
    }

    /**
     * Get the submission instance
     */
    public function getSubmission()
    {
        return $this->submission;
    }
}
