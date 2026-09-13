<?php

declare(strict_types=1);

/** @var array{environment?: array<string, string>, server?: array<string, string>} $sources */
$sources = json_decode($argv[1] ?? '{}', true, flags: JSON_THROW_ON_ERROR);

foreach ($sources['environment'] ?? [] as $name => $value) {
    $_ENV[$name] = $value;
}

foreach ($sources['server'] ?? [] as $name => $value) {
    $_SERVER[$name] = $value;
}

require dirname(__DIR__, 2).'/bootstrap.php';

$names = ['APP_ENV', 'DB_CONNECTION', 'DB_DATABASE', 'DB_URL'];
$values = [];

foreach ($names as $name) {
    $values[$name] = [
        'getenv' => getenv($name),
        'environment' => $_ENV[$name] ?? null,
        'server' => $_SERVER[$name] ?? null,
    ];
}

echo json_encode($values, JSON_THROW_ON_ERROR);
