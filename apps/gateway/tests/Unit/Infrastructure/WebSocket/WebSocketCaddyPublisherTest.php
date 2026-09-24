<?php

declare(strict_types=1);

use App\Infrastructure\WebSocket\WebSocketCaddyPublisher;
use App\Infrastructure\WebSocket\WebSocketCaddySiteRenderer;
use App\Infrastructure\WebSocket\WebSocketFootprint;
use Symfony\Component\Process\Process;

it('leaves the bind address for the node to choose', function (): void {
    $site = new WebSocketCaddySiteRenderer()->render(8790);

    expect($site)
        ->toContain('bind '.WebSocketFootprint::CaddyBindPlaceholder)
        ->not->toContain('bind 0.0.0.0')
        ->toContain('reverse_proxy 127.0.0.1:8790');
});

it('binds the address the node\'s other Caddy sites already bind', function (): void {
    $command = new WebSocketCaddyPublisher()->command('site', '8790', '10.44.0.1');

    expect($command->arguments)->toContain('10.44.0.1', WebSocketFootprint::CaddyBindPlaceholder);
    expect($command->input)->toContain('sed "s/$bind_placeholder/$bind_address/"');
});

it('joins the WireGuard listener when another site binds it, and otherwise follows a wildcard', function (array $fragments, string $expected): void {
    $command = new WebSocketCaddyPublisher()->command('site', '8790', '10.44.0.1');
    expect(preg_match('/bind_address=\$wireguard_ip.*?\n\s*fi\n/s', $command->input, $decision))->toBe(1);

    $candidate = sys_get_temp_dir().'/orbit-websocket-bind-'.bin2hex(random_bytes(6));
    mkdir($candidate.'/fragments', 0700, true);
    foreach ($fragments as $name => $contents) {
        file_put_contents("{$candidate}/fragments/{$name}.caddy", $contents);
    }

    $process = new Process(
        ['bash', '-euc', 'wireguard_ip=$1; candidate=$2; '.$decision[0].'printf %s "$bind_address"', 'decision', '10.44.0.1', $candidate],
    );
    $process->mustRun();
    array_map(unlink(...), glob($candidate.'/fragments/*') ?: []);
    rmdir($candidate.'/fragments');
    rmdir($candidate);

    expect($process->getOutput())->toBe($expected);
})->with([
    'no other site' => [[], '10.44.0.1'],
    'wildcard sites only' => [['app-dev' => "e2e-dev.orbit {\n\tbind 0.0.0.0\n}\n"], '0.0.0.0'],
    'WireGuard sites only' => [['gateway' => "gateway.orbit {\n\tbind 10.44.0.1\n}\n"], '10.44.0.1'],
    'wildcard beside a WireGuard site' => [[
        'app-dev' => "e2e-dev.orbit {\n\tbind 0.0.0.0\n}\n",
        'gateway' => "gateway.orbit, 10.44.0.1 {\n\tbind 10.44.0.1\n}\n",
    ], '10.44.0.1'],
    'another address that shares a prefix' => [[
        'app-dev' => "e2e-dev.orbit {\n\tbind 0.0.0.0\n}\n",
        'other' => "other.orbit {\n\tbind 10.44.0.11\n}\n",
    ], '0.0.0.0'],
]);

it('restores the previous Caddyfile when Caddy rejects the new one', function (string $script): void {
    expect($script)
        ->toContain('previous_target=$(readlink -- "$live_caddyfile" || true)')
        ->toContain('if ! systemctl reload-or-restart "$caddy_service"; then')
        ->toContain('ln -s -- "$previous_target" "$candidate_link"')
        ->toContain('systemctl restart "$caddy_service" || true')
        ->toContain('rm -rf -- "$published"')
        ->toContain('exit 1');
})->with([
    'publish' => fn (): string => (string) new WebSocketCaddyPublisher()->command('site', '8790', '10.44.0.1')->input,
    'remove' => fn (): string => (string) new WebSocketCaddyPublisher()->removeCommand()->input,
]);
