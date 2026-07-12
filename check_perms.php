<?php
header('Content-Type: text/plain');
$dbPath = '/var/www/html/licensing/licensing_data/sanad_licenses.sqlite';
if (file_exists($dbPath)) {
    echo "=== DB File Info ===\n";
    echo "Path: $dbPath\n";
    echo "Permissions: " . substr(sprintf('%o', fileperms($dbPath)), -4) . "\n";
    echo "Owner UID: " . fileowner($dbPath) . "\n";
    echo "Group GID: " . filegroup($dbPath) . "\n";
    
    $dir = dirname($dbPath);
    echo "\n=== Parent Directory Info ===\n";
    echo "Path: $dir\n";
    echo "Permissions: " . substr(sprintf('%o', fileperms($dir)), -4) . "\n";
    echo "Owner UID: " . fileowner($dir) . "\n";
    echo "Group GID: " . filegroup($dir) . "\n";
} else {
    echo "DB File not found: $dbPath\n";
}
