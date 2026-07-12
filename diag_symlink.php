<?php
header('Content-Type: text/plain');

function check_path($path) {
    echo "=== Checking $path ===\n";
    if (file_exists($path)) {
        echo "Exists: Yes\n";
        echo "Is Link: " . (is_link($path) ? 'Yes' : 'No') . "\n";
        if (is_link($path)) {
            echo "Points to: " . readlink($path) . "\n";
        }
        echo "Is Dir: " . (is_dir($path) ? 'Yes' : 'No') . "\n";
    } else {
        echo "Exists: No\n";
    }
    echo "\n";
}

check_path('/var/www/html/licensing');
check_path('/var/www/html/mod/quiz/accessrule/sanad_lockdown');
check_path('/var/www/html/mod/quiz/accessrule/ewa_lockdown');
