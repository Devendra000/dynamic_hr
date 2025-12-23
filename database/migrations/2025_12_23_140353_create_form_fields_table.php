<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('form_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_template_id')->constrained('form_templates')->onDelete('cascade');
            $table->enum('field_type', ['text', 'textarea', 'number', 'email', 'date', 'dropdown', 'checkbox', 'radio', 'file']);
            $table->string('label');
            $table->string('placeholder')->nullable();
            $table->json('options')->nullable(); // For dropdown, checkbox, radio
            $table->json('validation_rules')->nullable(); // min, max, regex, etc.
            $table->boolean('is_required')->default(false);
            $table->integer('order')->default(0);
            $table->timestamps();
            
            $table->index('form_template_id');
            $table->index('order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('form_fields');
    }
};
