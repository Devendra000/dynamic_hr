<?php

require 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$filePath = 'imports/694ceaef4fc08_1766648559_people10k.csv';
$fullPath = \Illuminate\Support\Facades\Storage::path($filePath);

echo "File path: $filePath\n";
echo "Full path: $fullPath\n";
echo "File exists: " . (file_exists($fullPath) ? 'YES' : 'NO') . "\n";
echo "Storage exists: " . (\Illuminate\Support\Facades\Storage::exists($filePath) ? 'YES' : 'NO') . "\n";

if (file_exists($fullPath)) {
    echo "File size: " . filesize($fullPath) . " bytes\n";
    $handle = fopen($fullPath, 'r');
    if ($handle) {
        $firstLine = fgets($handle);
        echo "First line: " . substr($firstLine, 0, 100) . "...\n";
        fclose($handle);
    }
}