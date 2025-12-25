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
        Schema::table('form_imports', function (Blueprint $table) {
            $table->integer('total_rows')->default(0)->after('skipped_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('form_imports', function (Blueprint $table) {
            $table->dropColumn('total_rows');
        });
    }
};