<?php

require 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$import = \App\Models\FormImport::find(1);
if($import) {
    $import->update(['status' => 'failed']);
    echo "Import ID {$import->id} status set to failed\n";
} else {
    echo "Import not found\n";
}