<?php

declare(strict_types=1);

use App\Domain\Hibernation\RuntimeHibernation;
use App\Infrastructure\Hibernation\RemoteHibernationMarkerStore;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use Tests\Support\AppDevFakeSshExecutor;

it('writes the awake marker after creating the tmpfs and log directories', function (): void {
    $ssh = new AppDevFakeSshExecutor([
        new CommandResult(0, '', '', 1, false),
        new CommandResult(0, '', '', 1, false),
        new CommandResult(0, '', '', 1, false),
        new CommandResult(0, '', '', 1, false),
    ]);
    $store = new RemoteHibernationMarkerStore(
        ssh: $ssh,
        keys: new HibernationFakeSshKeyProvider,
        knownHosts: new HibernationFakeKnownHostsStore,
    );

    $store->markAwake(hibernation_marker_node(), RuntimeHibernation::key(6));

    expect(array_map(static fn ($command): array => $command->arguments, $ssh->commands))
        ->toBe([
            ['sudo', 'install', '-d', '-o', 'root', '-g', 'caddy', '-m', '0755', RuntimeHibernation::MarkerDirectory],
            ['sudo', 'install', '-d', '-o', 'root', '-g', 'caddy', '-m', '2775', RuntimeHibernation::AccessLogDirectory],
            ['sudo', 'touch', '--', RuntimeHibernation::awakePath('app-instance-6')],
            ['sudo', 'chmod', '0644', '--', RuntimeHibernation::awakePath('app-instance-6')],
        ]);
});

it('uses the later of the access log and awake marker as last activity', function (): void {
    $ssh = new AppDevFakeSshExecutor([
        new CommandResult(0, "100\n", '', 1, false),
        new CommandResult(0, "200\n", '', 1, false),
    ]);
    $store = new RemoteHibernationMarkerStore(
        ssh: $ssh,
        keys: new HibernationFakeSshKeyProvider,
        knownHosts: new HibernationFakeKnownHostsStore,
    );

    expect($store->lastActivityUnix(hibernation_marker_node(), RuntimeHibernation::key(6)))
        ->toBe(200)
        ->and(array_map(static fn ($command): array => $command->arguments, $ssh->commands))
        ->toBe([
            ['sudo', 'stat', '-c', '%Y', '--', RuntimeHibernation::accessLogPath('app-instance-6')],
            ['sudo', 'stat', '-c', '%Y', '--', RuntimeHibernation::awakePath('app-instance-6')],
        ]);
});

it('treats a missing log and missing awake marker as no activity', function (): void {
    $ssh = new AppDevFakeSshExecutor([
        new CommandResult(1, '', 'missing', 1, false),
        new CommandResult(1, '', 'missing', 1, false),
    ]);
    $store = new RemoteHibernationMarkerStore(
        ssh: $ssh,
        keys: new HibernationFakeSshKeyProvider,
        knownHosts: new HibernationFakeKnownHostsStore,
    );

    expect($store->lastActivityUnix(hibernation_marker_node(), RuntimeHibernation::key(6)))
        ->toBeNull();
});

function hibernation_marker_node(): Node
{
    return new Node([
        'name' => 'app-dev',
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.3',
    ]);
}

final class HibernationFakeSshKeyProvider implements SshKeyProvider
{
    public function privateKeyPath(): string
    {
        return '/orbit/ssh/id_ed25519';
    }

    public function publicKey(): string
    {
        return 'ssh-ed25519 test';
    }
}

final class HibernationFakeKnownHostsStore implements KnownHostsStore
{
    public function path(): string
    {
        return '/orbit/ssh/known_hosts';
    }

    public function put(string $host, int $port, HostKey $key): void {}
}
