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
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 20)->nullable()->after('email');
            $table->string('department', 100)->nullable()->after('phone');
            $table->string('position', 100)->nullable()->after('department');
            $table->string('employee_id', 50)->unique()->nullable()->after('position');
            $table->date('hire_date')->nullable()->after('employee_id');
            $table->decimal('salary', 10, 2)->nullable()->after('hire_date');
            $table->enum('status', ['active', 'inactive', 'suspended'])->default('active')->after('salary');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'phone',
                'department',
                'position',
                'employee_id',
                'hire_date',
                'salary',
                'status'
            ]);
        });
    }
};
