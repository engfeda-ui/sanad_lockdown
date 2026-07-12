<?php
header('Content-Type: text/plain; charset=utf-8');

echo "=== System Info ===\n";
echo "Current PHP User: " . shell_exec('whoami') . "\n";
echo "Document Root: " . $_SERVER['DOCUMENT_ROOT'] . "\n";

$target_path = '/var/www/html/mod/quiz/accessrule/ewa_lockdown';
echo "\n=== Target Path Check ===\n";
echo "Target Path: $target_path\n";
if (file_exists($target_path)) {
    echo "Exists: Yes\n";
    echo "Is Dir: " . (is_dir($target_path) ? 'Yes' : 'No') . "\n";
    echo "Is Writable: " . (is_writable($target_path) ? 'Yes' : 'No') . "\n";
    $owner = posix_getpwuid(fileowner($target_path));
    echo "Owner: " . $owner['name'] . "\n";
    $group = posix_getgrgid(filegroup($target_path));
    echo "Group: " . $group['name'] . "\n";
    
    // Try to recursively delete the folder using PHP
    echo "\n=== Attempting Recursive Delete ===\n";
    function rrmdir($dir) {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object != "." && $object != "..") {
                    if (is_dir($dir. DIRECTORY_SEPARATOR .$object) && !is_link($dir."/".$object)) {
                        rrmdir($dir. DIRECTORY_SEPARATOR .$object);
                    } else {
                        unlink($dir. DIRECTORY_SEPARATOR .$object);
                    }
                }
            }
            return rmdir($dir);
        }
        return false;
    }
    
    if (rrmdir($target_path)) {
        echo "Success: Deleted $target_path successfully via PHP!\n";
    } else {
        echo "Failed to delete $target_path via PHP.\n";
    }
} else {
    echo "Exists: No (The folder is not there anymore!)\n";
}

echo "\n=== Shell Directory Listing ===\n";
echo shell_exec('ls -la /var/www/html/mod/quiz/accessrule/');

echo "\n=== EWA Lockdown Folder Details ===\n";
echo shell_exec('ls -la /var/www/html/mod/quiz/accessrule/ewa_lockdown/');
?>
