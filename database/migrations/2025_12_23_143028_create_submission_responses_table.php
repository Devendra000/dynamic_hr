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
        Schema::create('submission_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_submission_id')->constrained('form_submissions')->onDelete('cascade');
            $table->foreignId('form_field_id')->constrained('form_fields')->onDelete('cascade');
            $table->text('response_value')->nullable();
            $table->timestamps();
            
            $table->index('form_submission_id');
            $table->index('form_field_id');
            $table->index(['form_submission_id', 'form_field_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('submission_responses');
    }
};
