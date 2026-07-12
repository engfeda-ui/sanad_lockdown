<?php
header('Content-Type: text/plain');

$dbPath = '/var/www/html/licensing/licensing_data/sanad_licenses.sqlite';
if (!file_exists($dbPath)) {
    echo "Database file not found at: $dbPath\n";
    exit;
}

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    echo "Found database file at: $dbPath\n";
    
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
