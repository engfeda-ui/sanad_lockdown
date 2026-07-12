<?php
header('Content-Type: text/plain');
echo "=== Searching for SQLite databases ===\n";

$directory = new RecursiveDirectoryIterator('/var/www/html');
$iterator = new RecursiveIteratorIterator($directory);
foreach ($iterator as $info) {
    $filename = $info->getFilename();
    if (preg_match('/\.sqlite$|\.db$/i', $filename)) {
        echo "Path: " . $info->getPathname() . " | Size: " . $info->getSize() . " bytes\n";
    }
}

$directory_var = new RecursiveDirectoryIterator('/var/www');
$iterator_var = new RecursiveIteratorIterator($directory_var);
foreach ($iterator_var as $info) {
    // Avoid double printing /var/www/html
    if (strpos($info->getPathname(), '/var/www/html') === 0) continue;
    $filename = $info->getFilename();
    if (preg_match('/\.sqlite$|\.db$/i', $filename)) {
        echo "Path: " . $info->getPathname() . " | Size: " . $info->getSize() . " bytes\n";
    }
}
