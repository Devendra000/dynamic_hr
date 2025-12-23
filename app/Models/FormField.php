<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormField extends Model
{
    use HasFactory;

    protected $fillable = [
        'form_template_id',
        'field_type',
        'label',
        'placeholder',
        'options',
        'validation_rules',
        'is_required',
        'order'
    ];

    protected $casts = [
        'options' => 'array',
        'validation_rules' => 'array',
        'is_required' => 'boolean',
        'order' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the template this field belongs to
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(FormTemplate::class, 'form_template_id');
    }

    /**
     * Scope to order fields
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('order');
    }

    /**
     * Check if field requires options (dropdown, checkbox, radio)
     */
    public function requiresOptions(): bool
    {
        return in_array($this->field_type, ['dropdown', 'checkbox', 'radio']);
    }

    /**
     * Validate field value
     */
    public function validateValue($value): bool
    {
        if ($this->is_required && empty($value)) {
            return false;
        }

        // Add more validation based on field_type and validation_rules
        return true;
    }
}
