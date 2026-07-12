<?php
header('Content-Type: text/plain');

$paths = [
    '/var/www/licensing_data/sanad_licenses.sqlite',
    '/var/www/html/ewa_licensing_server/licensing_data/sanad_licenses.sqlite',
    '/var/www/licensing_data/ewa_licenses.sqlite',
    '/var/www/html/ewa_licensing_server/licensing_data/ewa_licenses.sqlite',
];

$dbPath = '';
foreach ($paths as $p) {
    if (file_exists($p)) {
        $dbPath = $p;
        echo "Found database file at: $p\n";
        break;
    }
}

if (empty($dbPath)) {
    echo "Database file not found in any expected location:\n";
    print_r($paths);
    exit;
}

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    echo "\n=== Clients Table ===\n";
    $clients = $pdo->query("SELECT id, name, moodle_url, client_token FROM clients")->fetchAll();
    foreach ($clients as $c) {
        echo "ID: {$c['id']} | Name: {$c['name']} | Moodle URL: {$c['moodle_url']} | Token: {$c['client_token']}\n";
    }
    
    echo "\n=== Users Table ===\n";
    $users = $pdo->query("SELECT id, username, email, role FROM users")->fetchAll();
    foreach ($users as $u) {
        echo "ID: {$u['id']} | Username: {$u['username']} | Email: {$u['email']} | Role: {$u['role']}\n";
    }
} catch (Exception $e) {
    echo "Error querying database: " . $e->getMessage() . "\n";
}
