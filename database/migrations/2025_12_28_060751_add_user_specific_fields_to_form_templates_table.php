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
        Schema::table('form_templates', function (Blueprint $table) {
            $table->enum('template_type', ['main', 'kye', 'kya'])->default('main')->after('status');
            $table->foreignId('parent_template_id')->nullable()->constrained('form_templates')->onDelete('cascade')->after('template_type');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->onDelete('cascade')->after('parent_template_id');
            
            $table->index('template_type');
            $table->index('parent_template_id');
            $table->index('assigned_to');
            $table->index(['template_type', 'assigned_to']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('form_templates', function (Blueprint $table) {
            $table->dropIndex(['template_type', 'assigned_to']);
            $table->dropIndex('assigned_to');
            $table->dropIndex('parent_template_id');
            $table->dropIndex('template_type');
            
            $table->dropForeign(['assigned_to']);
            $table->dropForeign(['parent_template_id']);
            
            $table->dropColumn(['template_type', 'parent_template_id', 'assigned_to']);
        });
    }
};
