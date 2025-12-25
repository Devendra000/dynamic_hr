<?php

require 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$import = \App\Models\FormImport::find(1);
if($import) {
    $import->update([
        'status' => 'failed',
        'imported_count' => 0,
        'skipped_count' => 0,
        'errors' => null
    ]);
    echo "Import reset to failed status\n";
} else {
    echo "Import not found\n";
}