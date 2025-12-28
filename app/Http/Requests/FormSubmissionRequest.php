<?php

namespace App\Http\Requests;

use App\Models\FormTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class FormSubmissionRequest extends FormRequest
{
    protected $formTemplate;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        if ($this->has('form_template_id')) {
            $template = FormTemplate::find($this->form_template_id);

            if (!$template) {
                // Template doesn't exist - this will be handled in validation
                return true; // Allow to proceed to validation which will catch the exists rule
            }

            // Store the template for later use
            $this->formTemplate = $template;

            // If it's a user-specific template, check based on template type
            if ($template->isUserSpecific()) {
                $user = auth()->user();

                if ($template->template_type === \App\Models\FormTemplate::TEMPLATE_TYPE_KYE) {
                    // KYE templates: Only the assigned employee can fill their own assessment
                    return $template->assigned_to === $user->id;
                }

                if ($template->template_type === \App\Models\FormTemplate::TEMPLATE_TYPE_KYA) {
                    // KYA templates: Only Admin and HR can fill evaluation forms
                    return $user->hasRole(['admin', 'hr']);
                }
            }

            // For main templates, any authenticated user can access
            return true;
        }

        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'form_template_id' => 'required|exists:form_templates,id',
            'status' => 'sometimes|in:draft,submitted',
            'responses' => 'required|array',
        ];

        // Load the form template if not already loaded
        if ($this->has('form_template_id')) {
            $this->formTemplate = FormTemplate::with('fields')->find($this->form_template_id);
            
            if ($this->formTemplate) {
                // Check if template is active
                if ($this->formTemplate->status !== FormTemplate::STATUS_ACTIVE) {
                    throw new HttpResponseException(response()->json([
                        'success' => false,
                        'message' => 'This form template is not currently active'
                    ], 422));
                }

                $isSubmitting = $this->input('status', 'draft') === 'submitted';

                // Add dynamic validation rules for each field
                foreach ($this->formTemplate->fields as $field) {
                    $fieldKey = "responses.{$field->id}";
                    $fieldRules = [];

                    // Required validation (only when submitting, not draft)
                    if ($field->is_required && $isSubmitting) {
                        $fieldRules[] = 'required';
                    }

                    // Type-specific validation
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

                    $rules[$fieldKey] = implode('|', $fieldRules);
                }

                // Validate that only valid field IDs are submitted
                $validFieldIds = $this->formTemplate->fields->pluck('id')->map(fn($id) => (string)$id)->toArray();
                $submittedFieldIds = array_keys($this->input('responses', []));
                $invalidFieldIds = array_diff($submittedFieldIds, $validFieldIds);

                if (!empty($invalidFieldIds)) {
                    throw new HttpResponseException(response()->json([
                        'success' => false,
                        'message' => 'Invalid field IDs: ' . implode(', ', $invalidFieldIds)
                    ], 422));
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
            'form_template_id.required' => 'Form template is required',
            'form_template_id.exists' => 'Selected form template does not exist',
            'status.in' => 'Status must be either draft or submitted',
            'responses.required' => 'Form responses are required',
            'responses.array' => 'Form responses must be an array',
        ];

        // Add dynamic messages for fields
        if ($this->formTemplate) {
            foreach ($this->formTemplate->fields as $field) {
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
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        $attributes = [];

        if ($this->formTemplate) {
            foreach ($this->formTemplate->fields as $field) {
                $attributes["responses.{$field->id}"] = $field->label;
            }
        }

        return $attributes;
    }

    /**
     * Handle a failed authorization attempt.
     */
    protected function failedAuthorization()
    {
        $template = FormTemplate::find($this->form_template_id);

        if (!$template) {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'message' => 'Form template not found'
            ], 404));
        }

        if ($template->isUserSpecific()) {
            $user = auth()->user();

            if ($template->template_type === \App\Models\FormTemplate::TEMPLATE_TYPE_KYE) {
                throw new HttpResponseException(response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to submit this assessment form'
                ], 403));
            }

            if ($template->template_type === \App\Models\FormTemplate::TEMPLATE_TYPE_KYA) {
                throw new HttpResponseException(response()->json([
                    'success' => false,
                    'message' => 'Only administrators and HR personnel can submit evaluation forms'
                ], 403));
            }
        }

        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'You do not have permission to submit this form'
        ], 403));
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
     * Get the form template instance
     */
    public function getFormTemplate()
    {
        return $this->formTemplate;
    }
}
