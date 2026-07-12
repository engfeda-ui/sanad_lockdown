<?php
header('Content-Type: text/plain');
echo "=== Reading /var/www/html/licensing/config.php ===\n";
$config_path = '/var/www/html/licensing/config.php';
if (file_exists($config_path)) {
    $content = file_get_contents($config_path);
    // Strip php tags to avoid execution
    echo str_replace(['<?php', '?>'], '', $content) . "\n";
} else {
    echo "File not found: $config_path\n";
}

echo "\n=== Reading /var/www/html/ewa_licensing_server/config.php ===\n";
$old_config_path = '/var/www/html/ewa_licensing_server/config.php';
if (file_exists($old_config_path)) {
    $content = file_get_contents($old_config_path);
    echo str_replace(['<?php', '?>'], '', $content) . "\n";
} else {
    echo "File not found: $old_config_path\n";
}
