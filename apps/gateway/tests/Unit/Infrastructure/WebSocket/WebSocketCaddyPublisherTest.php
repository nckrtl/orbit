<?php

declare(strict_types=1);

use App\Infrastructure\WebSocket\WebSocketCaddyPublisher;
use App\Infrastructure\WebSocket\WebSocketCaddySiteRenderer;
use App\Infrastructure\WebSocket\WebSocketFootprint;

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
    expect($command->input)
        ->toContain('bind_address=$wireguard_ip')
        ->toContain('bind_address=0.0.0.0')
        ->toContain('sed "s/$bind_placeholder/$bind_address/"');
});

it('restores the previous Caddyfile when Caddy rejects the new one', function (string $script): void {
    expect($script)
        ->toContain('previous_target=$(readlink -- "$live_caddyfile" || true)')
        ->toContain('if ! systemctl reload-or-restart "$caddy_service"; then')
        ->toContain('ln -s -- "$previous_target" "$candidate_link"')
        ->toContain('rm -rf -- "$published"')
        ->toContain('exit 1');
})->with([
    'publish' => fn (): string => (string) new WebSocketCaddyPublisher()->command('site', '8790', '10.44.0.1')->input,
    'remove' => fn (): string => (string) new WebSocketCaddyPublisher()->removeCommand()->input,
]);
