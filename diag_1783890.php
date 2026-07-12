<?php
header('Content-Type: text/plain');
echo "=== Licensing Folder Check ===\n";
$dir = '/var/www/html/licensing';
if (is_dir($dir)) {
    echo "Directory exists: Yes\n";
    echo "Permissions: " . substr(sprintf('%o', fileperms($dir)), -4) . "\n";
    $owner = posix_getpwuid(fileowner($dir));
    $group = posix_getgrgid(filegroup($dir));
    echo "Owner: " . ($owner ? $owner['name'] : fileowner($dir)) . "\n";
    echo "Group: " . ($group ? $group['name'] : filegroup($dir)) . "\n";
    echo "Is Writable by PHP: " . (is_writable($dir) ? 'Yes' : 'No') . "\n";
    
    echo "\n=== Files in $dir ===\n";
    foreach (scandir($dir) as $file) {
        if ($file === '.' || $file === '..') continue;
        $path = $dir . '/' . $file;
        $fperms = substr(sprintf('%o', fileperms($path)), -4);
        $fowner = posix_getpwuid(fileowner($path));
        $fowner_name = $fowner ? $fowner['name'] : fileowner($path);
        echo "$file | Owner: $fowner_name | Perms: $fperms\n";
    }
} else {
    echo "Directory $dir does not exist\n";
}

echo "\n=== Old ewa_licensing_server Folder Check ===\n";
$old_dir = '/var/www/html/ewa_licensing_server';
if (is_dir($old_dir)) {
    echo "Directory exists: Yes\n";
    echo "Permissions: " . substr(sprintf('%o', fileperms($old_dir)), -4) . "\n";
    $owner = posix_getpwuid(fileowner($old_dir));
    $group = posix_getgrgid(filegroup($old_dir));
    echo "Owner: " . ($owner ? $owner['name'] : fileowner($old_dir)) . "\n";
    echo "Group: " . ($group ? $group['name'] : filegroup($old_dir)) . "\n";
} else {
    echo "Directory $old_dir does not exist\n";
}
