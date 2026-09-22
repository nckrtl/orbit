<?php

declare(strict_types=1);

use App\Infrastructure\AppDev\AppDevCaddyPublisher;
use App\Infrastructure\Ssh\RemoteCommand;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$root = $argv[1];
$port = random_int(20000, 50000);
$publisher = new AppDevCaddyPublisher(
    versionsDirectory: $root.'/etc/caddy/orbit-versions',
    liveCaddyfilePath: $root.'/etc/caddy/Caddyfile',
    lockPath: $root.'/locks/caddy.lock',
    hibernationMarkerDirectory: $root.'/markers',
    hibernationAccessLogDirectory: $root.'/logs',
);
$configuration = static fn (string $response, string $log): string => <<<CADDY
    http://127.0.0.1:{$port} {
        log {
            output file {$root}/logs/{$log}
        }
        respond "{$response}"
    }
    CADDY;
$serialize = static fn (RemoteCommand $command): array => [
    'arguments' => array_slice($command->arguments, 1),
    'input' => $command->input,
];

echo json_encode([
    'root' => $root,
    'port' => $port,
    'publish' => $serialize($publisher->command($configuration('first', 'access.log'), 'first')),
    'reload' => $serialize($publisher->command($configuration('second', 'access.log'), 'second')),
    'invalid' => $serialize($publisher->command('not_a_caddy_directive', 'invalid')),
    'blocked' => $serialize($publisher->command($configuration('blocked', 'blocked.log'), 'blocked')),
    'retry' => $serialize($publisher->command($configuration('retry', 'access.log'), 'retry')),
    'remove' => $serialize($publisher->removeCommand('removed')),
], JSON_THROW_ON_ERROR);
