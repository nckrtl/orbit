<?php

declare(strict_types=1);

use Illuminate\Process\Factory as ProcessFactory;

/** @return array{root:string,database:string,script:string,environment:array<string,string>} */
function extendedRuntimeFixture(): array
{
    $root = temporaryPath('orbit-extended-runtime-', 5);
    mkdir($root.'/bin', 0o700, true);
    $database = $root.'/gateway.sqlite';
    $pdo = new PDO('sqlite:'.$database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    foreach ([
        'CREATE TABLE nodes (id INTEGER PRIMARY KEY, name TEXT NOT NULL, status TEXT NOT NULL)',
        'CREATE TABLE instances (id INTEGER PRIMARY KEY)',
        'CREATE TABLE workspaces (id INTEGER PRIMARY KEY)',
        'CREATE TABLE app_instances (id INTEGER PRIMARY KEY, name TEXT NOT NULL, node_id INTEGER NOT NULL, status TEXT NOT NULL)',
        'CREATE TABLE routes (id INTEGER PRIMARY KEY, node_id INTEGER, generation_basis_node_id INTEGER)',
        'CREATE TABLE route_targets (id INTEGER PRIMARY KEY, route_id INTEGER NOT NULL, app_instance_id INTEGER NOT NULL)',
    ] as $statement) {
        $pdo->exec($statement);
    }
    $pdo->exec(
        "INSERT INTO nodes VALUES (2, 'app-dev', 'active'), (3, 'app-prod', 'active'), (4, 'app-prod-2', 'active')",
    );
    $pdo->exec("INSERT INTO app_instances VALUES (10, 'e2e-dev', 2, 'active')");
    $pdo->exec('INSERT INTO routes VALUES (20, NULL, NULL)');
    $pdo->exec('INSERT INTO route_targets VALUES (30, 20, 10)');

    file_put_contents($root.'/bin/ping', <<<'BASH'
        #!/usr/bin/env bash
        set -euo pipefail
        [[ "${EXTENDED_CONNECTIVITY_FAILURE:-0}" != 1 ]]
        BASH);
    file_put_contents($root.'/bin/sudo', <<<'BASH'
        #!/usr/bin/env bash
        set -euo pipefail
        [[ "$*" == *'ssh -n -o BatchMode=yes -o ConnectTimeout=5 -o StrictHostKeyChecking=yes -o UserKnownHostsFile=/home/orbit/.orbit/ssh/known_hosts -i /home/orbit/.orbit/ssh/id_ed25519 orbit@10.44.0.4 '* ]]
        case "${EXTENDED_RUNTIME_FAILURE:-}" in
          '') exit 0 ;;
          php) [[ "$*" == *"php -r"* ]] && exit 1 || exit 0 ;;
          php-fpm) [[ "$*" == *"systemctl is-active php8.5-fpm"* ]] && exit 1 || exit 0 ;;
          caddy-service) [[ "$*" == *"systemctl is-active caddy"* ]] && exit 1 || exit 0 ;;
          caddy-configuration) [[ "$*" == *"caddy validate --config /etc/caddy/Caddyfile"* ]] && exit 1 || exit 0 ;;
          *) exit 64 ;;
        esac
        BASH);
    chmod($root.'/bin/ping', 0o700);
    chmod($root.'/bin/sudo', 0o700);

    return [
        'root' => $root,
        'database' => $database,
        'script' => dirname(__DIR__, 5).'/.loop/proof/extended-runtime-connectivity.sh',
        'environment' => [
            'PATH' => $root.'/bin:'.getenv('PATH'),
            'ORBIT_E2E_GATEWAY_DB' => $database,
        ],
    ];
}

/** @param array{script:string,environment:array<string,string>} $fixture */
function runExtendedRuntimeFixture(array $fixture, array $environment = []): \Illuminate\Process\InvokedProcess
{
    return new ProcessFactory()->env([...$fixture['environment'], ...$environment])->start([
        'bash',
        $fixture['script'],
        'app-dev',
        'app-prod',
        'app-prod-2',
        '10.44.0.4',
    ]);
}

it('keeps no tracked legacy proof archive', function (): void {
    $root = dirname(__DIR__, 5);
    $result = new ProcessFactory()->path($root)->run(['git', 'ls-files', 'proofs']);

    expect($result->successful())
        ->toBeTrue()
        ->and(trim($result->output()))
        ->toBe('')
        ->and(is_dir($root.'/proofs'))
        ->toBeFalse();
});

it('keeps fixture contract tests independent of individual issue artifacts', function (): void {
    $tests = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2)));
    $individualIssue = '/(?:'.'N'.'CK|O'.'RB)-[0-9]+/';
    $individualFile = '/(?:'.'Nck|Orb)[0-9]+/';

    foreach ($tests as $test) {
        if (! $test->isFile() || $test->getExtension() !== 'php') {
            continue;
        }
        expect($test->getFilename())
            ->not->toMatch($individualFile)->and((string) file_get_contents($test->getPathname()))
            ->not->toMatch($individualIssue);
    }
});

it('accepts only the sole app-dev sample placement, single-target routes, and the complete extra runtime', function (): void {
    $fixture = extendedRuntimeFixture();
    try {
        $result = runExtendedRuntimeFixture($fixture)->wait();

        expect($result->successful())
            ->toBeTrue()
            ->and($result->output())
            ->toContain('extended-runtime-connectivity: ok');
    } finally {
        new Illuminate\Filesystem\Filesystem()->deleteDirectory($fixture['root']);
    }
});

it('refuses each altered post-convergence graph and runtime condition', function (
    Closure $alter,
    array $environment,
): void {
    $fixture = extendedRuntimeFixture();
    try {
        $pdo = new PDO('sqlite:'.$fixture['database'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $alter($pdo);
        $result = runExtendedRuntimeFixture($fixture, $environment)->wait();

        expect($result->successful())->toBeFalse();
    } finally {
        new Illuminate\Filesystem\Filesystem()->deleteDirectory($fixture['root']);
    }
})->with([
    'altered graph table' => [fn (PDO $pdo) => $pdo->exec('DROP TABLE route_targets'), []],
    'legacy Instance' => [fn (PDO $pdo) => $pdo->exec('INSERT INTO instances VALUES (1)'), []],
    'Workspace' => [fn (PDO $pdo) => $pdo->exec('INSERT INTO workspaces VALUES (1)'), []],
    'altered sample name' => [fn (PDO $pdo) => $pdo->exec("UPDATE app_instances SET name = 'other' WHERE id = 10"), []],
    'inactive sample' => [fn (PDO $pdo) => $pdo->exec("UPDATE app_instances SET status = 'failed' WHERE id = 10"), []],
    'sample placed on original app-prod' => [
        fn (PDO $pdo) => $pdo->exec('UPDATE app_instances SET node_id = 3 WHERE id = 10'),
        [],
    ],
    'sample placed on extra app-prod' => [
        fn (PDO $pdo) => $pdo->exec('UPDATE app_instances SET node_id = 4 WHERE id = 10'),
        [],
    ],
    'additional original-node AppInstance' => [
        fn (PDO $pdo) => $pdo->exec("INSERT INTO app_instances VALUES (11, 'e2e-prod', 3, 'active')"),
        [],
    ],
    'additional extra-node AppInstance' => [
        fn (PDO $pdo) => $pdo->exec("INSERT INTO app_instances VALUES (11, 'e2e-prod', 4, 'active')"),
        [],
    ],
    'multiple Route targets' => [fn (PDO $pdo) => $pdo->exec('INSERT INTO route_targets VALUES (31, 20, 10)'), []],
    'unknown Route target' => [
        fn (PDO $pdo) => $pdo->exec('UPDATE route_targets SET app_instance_id = 999 WHERE id = 30'),
        [],
    ],
    'original-node Route scope' => [fn (PDO $pdo) => $pdo->exec('UPDATE routes SET node_id = 3 WHERE id = 20'), []],
    'extra-node Route scope' => [fn (PDO $pdo) => $pdo->exec('UPDATE routes SET node_id = 4 WHERE id = 20'), []],
    'original-node generation basis' => [
        fn (PDO $pdo) => $pdo->exec('UPDATE routes SET generation_basis_node_id = 3 WHERE id = 20'),
        [],
    ],
    'extra-node generation basis' => [
        fn (PDO $pdo) => $pdo->exec('UPDATE routes SET generation_basis_node_id = 4 WHERE id = 20'),
        [],
    ],
    'connectivity' => [fn (PDO $_pdo) => null, ['EXTENDED_CONNECTIVITY_FAILURE' => '1']],
    'PHP runtime' => [fn (PDO $_pdo) => null, ['EXTENDED_RUNTIME_FAILURE' => 'php']],
    'PHP-FPM service' => [fn (PDO $_pdo) => null, ['EXTENDED_RUNTIME_FAILURE' => 'php-fpm']],
    'Caddy service' => [fn (PDO $_pdo) => null, ['EXTENDED_RUNTIME_FAILURE' => 'caddy-service']],
    'Caddy configuration' => [fn (PDO $_pdo) => null, ['EXTENDED_RUNTIME_FAILURE' => 'caddy-configuration']],
]);
