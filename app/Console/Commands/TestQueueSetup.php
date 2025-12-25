<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\DB;

class TestQueueSetup extends Command
{
    protected $signature = 'queue:test-setup';
    protected $description = 'Test if queue setup is complete and working';

    public function handle()
    {
        $this->info('Testing Queue Import Setup...');
        $this->newLine();

        $passed = 0;
        $failed = 0;

        // Test 1: Redis Connection
        $this->info('1. Testing Redis connection...');
        try {
            Redis::ping();
            $this->info('   ✓ Redis connection successful');
            $passed++;
        } catch (\Exception $e) {
            $this->error('   ✗ Redis connection failed: ' . $e->getMessage());
            $failed++;
        }

        // Test 2: Database Connection
        $this->info('2. Testing database connection...');
        try {
            DB::connection()->getPdo();
            $this->info('   ✓ Database connection successful');
            $passed++;
        } catch (\Exception $e) {
            $this->error('   ✗ Database connection failed: ' . $e->getMessage());
            $failed++;
        }

        // Test 3: form_imports table exists
        $this->info('3. Checking form_imports table...');
        try {
            if (DB::getSchemaBuilder()->hasTable('form_imports')) {
                $this->info('   ✓ form_imports table exists');
                $passed++;
            } else {
                $this->error('   ✗ form_imports table not found. Run: php artisan migrate');
                $failed++;
            }
        } catch (\Exception $e) {
            $this->error('   ✗ Error checking table: ' . $e->getMessage());
            $failed++;
        }

        // Test 4: Queue configuration
        $this->info('4. Checking queue configuration...');
        if (config('queue.default') === 'redis') {
            $this->info('   ✓ Queue configured to use Redis');
            $passed++;
        } else {
            $this->error('   ✗ Queue not configured for Redis. Current: ' . config('queue.default'));
            $failed++;
        }

        // Test 5: Storage directory
        $this->info('5. Checking storage directory...');
        $importPath = storage_path('app/imports');
        if (!is_dir($importPath)) {
            mkdir($importPath, 0775, true);
        }
        if (is_writable(storage_path('app'))) {
            $this->info('   ✓ Storage directory is writable');
            $passed++;
        } else {
            $this->error('   ✗ Storage directory not writable');
            $failed++;
        }

        // Test 6: Predis package
        $this->info('6. Checking Predis package...');
        if (class_exists(\Predis\Client::class)) {
            $this->info('   ✓ Predis package installed');
            $passed++;
        } else {
            $this->error('   ✗ Predis package not found. Run: composer require predis/predis');
            $failed++;
        }

        // Test 7: Required models
        $this->info('7. Checking required models...');
        if (class_exists(\App\Models\FormImport::class)) {
            $this->info('   ✓ FormImport model exists');
            $passed++;
        } else {
            $this->error('   ✗ FormImport model not found');
            $failed++;
        }

        // Test 8: Required job
        $this->info('8. Checking required job...');
        if (class_exists(\App\Jobs\ProcessFormImport::class)) {
            $this->info('   ✓ ProcessFormImport job exists');
            $passed++;
        } else {
            $this->error('   ✗ ProcessFormImport job not found');
            $failed++;
        }

        // Summary
        $this->newLine();
        $this->line(str_repeat('=', 50));
        $this->info("Tests Passed: {$passed}");
        if ($failed > 0) {
            $this->error("Tests Failed: {$failed}");
        } else {
            $this->info("Tests Failed: {$failed}");
        }
        $this->line(str_repeat('=', 50));

        if ($failed === 0) {
            $this->newLine();
            $this->info('✓ All tests passed! Queue import setup is complete.');
            $this->newLine();
            $this->comment('Next steps:');
            $this->line('1. Start queue worker: php artisan queue:work redis --queue=default --tries=3 --timeout=3600');
            $this->line('2. Test import via API endpoint');
            $this->newLine();
            return 0;
        } else {
            $this->newLine();
            $this->error('✗ Some tests failed. Please fix the issues above.');
            $this->newLine();
            return 1;
        }
    }
}
