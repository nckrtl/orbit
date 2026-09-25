<?php

declare(strict_types=1);

use App\Infrastructure\Caddy\CaddyFragmentListeners;
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

it('binds the site to the shared listener the Gateway chose for the Node', function (): void {
    $command = new WebSocketCaddyPublisher()->command(
        new WebSocketCaddySiteRenderer()->render(8790),
        '8790',
        new CaddyFragmentListeners(['10.44.0.1', '192.168.1.1'], ['10.44.0.1']),
    );

    expect($command->arguments)->not->toContain(WebSocketFootprint::CaddyBindPlaceholder)
        ->and($command->input)
        ->toContain(base64_encode(str_replace(
            WebSocketFootprint::CaddyBindPlaceholder,
            '10.44.0.1',
            new WebSocketCaddySiteRenderer()->render(8790),
        )))
        ->toContain("orbit_rewrite_listeners \"\$listener_fragment\" '10.44.0.1 192.168.1.1' https")
        ->not->toContain('bind_address');
});

it('restores the previous Caddyfile when Caddy rejects the new one', function (string $script): void {
    expect($script)
        ->toContain('previous_target=$(readlink -- "$live_caddyfile" || true)')
        ->toContain('if ! systemctl reload-or-restart "$caddy_service"; then')
        ->toContain('ln -s -- "$previous_target" "$candidate_link"')
        ->toContain('systemctl restart "$caddy_service" || true')
        ->toContain('rm -rf -- "$published"')
        ->toContain('exit 1');
})->with([
    'publish' => fn (): string => (string) new WebSocketCaddyPublisher()->command('site', '8790', new CaddyFragmentListeners(['10.44.0.1'], ['10.44.0.1']))->input,
    'remove' => fn (): string => (string) new WebSocketCaddyPublisher()->removeCommand()->input,
]);
