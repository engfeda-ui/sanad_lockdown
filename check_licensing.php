<?php
header('Content-Type: text/plain');
echo "=== System Info ===\n";
echo "Current PHP User: " . exec('whoami') . "\n";
echo "Document Root: " . $_SERVER['DOCUMENT_ROOT'] . "\n";

echo "\n=== Listing /var/www/html ===\n";
if (is_dir('/var/www/html')) {
    foreach (scandir('/var/www/html') as $file) {
        if ($file === '.' || $file === '..') continue;
        $path = '/var/www/html/' . $file;
        echo $file . (is_dir($path) ? ' (dir)' : ' (file)') . "\n";
    }
} else {
    echo "/var/www/html does not exist\n";
}

echo "\n=== Listing /home/ubuntu/moodle-project ===\n";
if (is_dir('/home/ubuntu/moodle-project')) {
    foreach (scandir('/home/ubuntu/moodle-project') as $file) {
        if ($file === '.' || $file === '..') continue;
        $path = '/home/ubuntu/moodle-project/' . $file;
        echo $file . (is_dir($path) ? ' (dir)' : ' (file)') . "\n";
    }
} else {
    echo "/home/ubuntu/moodle-project does not exist\n";
}
