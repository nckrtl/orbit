<?php

declare(strict_types=1);

$operation = $argv[1] ?? '';
$database = $argv[2] ?? '';

if ($operation === 'create') {
    $pdo = new PDO('sqlite:'.$database);
    $pdo->exec('CREATE TABLE sentinel (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
    $pdo->exec("INSERT INTO sentinel (id, value) VALUES (247, 'preserve-me')");
    unset($pdo);
}

$pdo = new PDO('sqlite:'.$database);
$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")
    ->fetchAll(PDO::FETCH_COLUMN);
$rows = $pdo->query('SELECT id, value FROM sentinel ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
unset($pdo);

echo json_encode([
    'hash' => hash_file('sha256', $database),
    'tables' => $tables,
    'rows' => $rows,
], JSON_THROW_ON_ERROR);
