<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormTemplate extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'title',
        'description',
        'status',
        'created_by',
        'template_type',
        'parent_template_id',
        'assigned_to'
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    // Field type constants
    const FIELD_TYPE_TEXT = 'text';
    const FIELD_TYPE_TEXTAREA = 'textarea';
    const FIELD_TYPE_NUMBER = 'number';
    const FIELD_TYPE_EMAIL = 'email';
    const FIELD_TYPE_DATE = 'date';
    const FIELD_TYPE_DROPDOWN = 'dropdown';
    const FIELD_TYPE_CHECKBOX = 'checkbox';
    const FIELD_TYPE_RADIO = 'radio';
    const FIELD_TYPE_FILE = 'file';

    // Template type constants
    const TEMPLATE_TYPE_MAIN = 'main';
    const TEMPLATE_TYPE_KYE = 'kye';
    const TEMPLATE_TYPE_KYA = 'kya';

    // Status constants
    const STATUS_ACTIVE = 'active';
    const STATUS_INACTIVE = 'inactive';
    const STATUS_DRAFT = 'draft';

    /**
     * Get the fields for this template
     */
    public function fields(): HasMany
    {
        return $this->hasMany(FormField::class)->orderBy('order');
    }

    /**
     * Get the user who created this template
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get all submissions for this template
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class);
    }

    /**
     * Get the parent template (for user-specific templates)
     */
    public function parentTemplate(): BelongsTo
    {
        return $this->belongsTo(FormTemplate::class, 'parent_template_id');
    }

    /**
     * Get all child templates (user-specific copies)
     */
    public function childTemplates(): HasMany
    {
        return $this->hasMany(FormTemplate::class, 'parent_template_id');
    }

    /**
     * Get the user this template is assigned to
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Scope to get only active templates
     */
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Scope to get only draft templates
     */
    public function scopeDraft($query)
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    /**
     * Scope to get templates created by a specific user
     */
    public function scopeCreatedBy($query, $userId)
    {
        return $query->where('created_by', $userId);
    }

    /**
     * Scope to get main templates only
     */
    public function scopeMainTemplates($query)
    {
        return $query->where('template_type', self::TEMPLATE_TYPE_MAIN);
    }

    /**
     * Scope to get KYE templates
     */
    public function scopeKyeTemplates($query)
    {
        return $query->where('template_type', self::TEMPLATE_TYPE_KYE);
    }

    /**
     * Scope to get KYA templates
     */
    public function scopeKyaTemplates($query)
    {
        return $query->where('template_type', self::TEMPLATE_TYPE_KYA);
    }

    /**
     * Scope to get user-specific templates
     */
    public function scopeUserSpecific($query)
    {
        return $query->whereIn('template_type', [self::TEMPLATE_TYPE_KYE, self::TEMPLATE_TYPE_KYA]);
    }

    /**
     * Scope to get templates assigned to a specific user
     */
    public function scopeAssignedTo($query, $userId)
    {
        return $query->where('assigned_to', $userId);
    }

    /**
     * Check if this is a user-specific template
     */
    public function isUserSpecific(): bool
    {
        return in_array($this->template_type, [self::TEMPLATE_TYPE_KYE, self::TEMPLATE_TYPE_KYA]);
    }

    /**
     * Check if this is a main template
     */
    public function isMainTemplate(): bool
    {
        return $this->template_type === self::TEMPLATE_TYPE_MAIN;
    }

    /**
     * Get field types
     */
    public static function getFieldTypes(): array
    {
        return [
            self::FIELD_TYPE_TEXT,
            self::FIELD_TYPE_TEXTAREA,
            self::FIELD_TYPE_NUMBER,
            self::FIELD_TYPE_EMAIL,
            self::FIELD_TYPE_DATE,
            self::FIELD_TYPE_DROPDOWN,
            self::FIELD_TYPE_CHECKBOX,
            self::FIELD_TYPE_RADIO,
            self::FIELD_TYPE_FILE,
        ];
    }

    /**
     * Get statuses
     */
    public static function getStatuses(): array
    {
        return [
            self::STATUS_ACTIVE,
            self::STATUS_INACTIVE,
            self::STATUS_DRAFT,
        ];
    }

    /**
     * Get template types
     */
    public static function getTemplateTypes(): array
    {
        return [
            self::TEMPLATE_TYPE_MAIN,
            self::TEMPLATE_TYPE_KYE,
            self::TEMPLATE_TYPE_KYA,
        ];
    }
}