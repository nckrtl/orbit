<?php

declare(strict_types=1);

use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Certificates\GatewayCertificateIssuer;
use App\Domain\Certificates\GatewayCertificatePaths;
use App\Domain\Nodes\NodeRoleOperationException;
use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Infrastructure\Caddy\Build\CaddySiteCertificates;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildException;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\WebSocket\NativeWebSocketPublicationManager;
use App\Infrastructure\WebSocket\WebSocketCertificatePublisher;
use App\Infrastructure\WebSocket\WebSocketDnsTarget;
use App\Models\Node;
use Tests\Support\RecordingNodeCaddyBuilds;

it('publishes the certificate, requests a Node Caddy build, then converges DNS last', function (): void {
    $events = [];
    $manager = websocket_publication_manager($events);

    $manager->converge(websocket_publication_node());

    expect($events)->toBe(['certificate:issue', 'ssh:certificate', 'build:websocket', 'dns:converge'])
        ->and(new CaddySiteCertificates()->published(websocket_publication_node()->id, CaddySiteCertificates::Websocket))->toBeTrue()
        ->and(websocket_publication_serving())->toBeTrue();
});

it('writes no Caddy file on the Node itself', function (): void {
    $events = [];
    $commands = [];
    $manager = websocket_publication_manager($events, commands: $commands);

    $manager->converge(websocket_publication_node());
    $manager->remove(websocket_publication_node());

    foreach ($commands as $command) {
        expect(implode(' ', $command->arguments)."\n".($command->input ?? ''))
            ->not->toContain('orbit-versions')
            ->not->toContain('/etc/caddy/Caddyfile')
            ->not->toContain('websocket.caddy');
    }
});

it('stops before the build when the certificate SSH push fails', function (): void {
    $events = [];
    $manager = websocket_publication_manager($events, failCertificate: true);

    expect(fn () => $manager->converge(websocket_publication_node()))
        ->toThrow(NodeRoleOperationException::class);

    expect($events)->toBe(['certificate:issue', 'ssh:certificate'])
        ->and(new CaddySiteCertificates()->published(websocket_publication_node()->id, CaddySiteCertificates::Websocket))->toBeFalse();
});

it('keeps its error code and names the Node, stage, and Caddy message when the build fails', function (): void {
    $events = [];
    $manager = websocket_publication_manager($events, buildFailure: new NodeCaddyBuildException('websocket', 'validate', 'Error: loading certificates'));

    expect(fn () => $manager->converge(websocket_publication_node()))
        ->toThrow(function (NodeRoleOperationException $exception): void {
            expect($exception->underlyingErrorCode)->toBe('websocket.caddy_publication_failed')
                ->and($exception->step)->toBe('websocket-caddy')
                ->and($exception->getMessage())->toBe('The Caddy build for Node [websocket] failed at stage [validate]: Error: loading certificates');
        });

    expect($events)->toBe(['certificate:issue', 'ssh:certificate', 'build:websocket'])
        ->and(websocket_publication_serving())->toBeFalse();
});

it('builds the Node before it removes the certificate, then converges DNS without the node', function (): void {
    $events = [];
    $manager = websocket_publication_manager($events);

    new CaddySiteCertificates()->record(websocket_publication_node()->id, CaddySiteCertificates::Websocket);
    new WebSocketDnsTarget()->markServing(websocket_publication_node()->id);

    $manager->remove(websocket_publication_node());

    expect($events)->toBe(['build:websocket', 'ssh:certificate-remove', 'dns:converge-empty'])
        ->and(new CaddySiteCertificates()->published(websocket_publication_node()->id, CaddySiteCertificates::Websocket))->toBeFalse()
        ->and(websocket_publication_serving())->toBeFalse();
});

it('keeps the Node published to while the build that withdraws its site runs', function (): void {
    $events = [];
    $servingDuringBuild = null;
    new CaddySiteCertificates()->record(websocket_publication_node()->id, CaddySiteCertificates::Websocket);
    new WebSocketDnsTarget()->markServing(websocket_publication_node()->id);
    $manager = websocket_publication_manager($events, onBuild: static function () use (&$servingDuringBuild): void {
        $servingDuringBuild = websocket_publication_serving();
    });

    $manager->remove(websocket_publication_node());

    expect($servingDuringBuild)->toBeTrue()
        ->and(websocket_publication_serving())->toBeFalse();
});

it('touches only the Gateway-side DNS record when the node is unreachable', function (): void {
    $events = [];
    $manager = websocket_publication_manager($events);

    new CaddySiteCertificates()->record(websocket_publication_node()->id, CaddySiteCertificates::Websocket);
    new WebSocketDnsTarget()->markServing(websocket_publication_node()->id);

    $manager->removeUnreachable(websocket_publication_node());

    expect($events)->toBe(['dns:converge-empty'])
        ->and(new CaddySiteCertificates()->published(websocket_publication_node()->id, CaddySiteCertificates::Websocket))->toBeFalse()
        ->and(websocket_publication_serving())->toBeFalse();
});

it('checks the listen addresses of the Node render without changing anything and names a missing one', function (): void {
    $events = [];

    websocket_publication_manager($events)->checkListenAddresses(websocket_publication_node());

    expect($events)->toBe(['check:websocket'])
        ->and(fn () => websocket_publication_manager($events, buildFailure: new NodeCaddyBuildException(
            'websocket',
            'addresses',
            'The build binds 192.168.6.30, which is not an address on this Node.',
        ))->checkListenAddresses(websocket_publication_node()))
        ->toThrow(NodeRoleOperationException::class, 'The build binds 192.168.6.30');
});

/** Whether private DNS may name the test Node for `reverb.orbit`. */
function websocket_publication_serving(): bool
{
    return new SettingRepository()->get(
        new SettingScope(SettingScopeType::Node, websocket_publication_node()->id),
        WebSocketDnsTarget::SettingKey,
    ) !== null;
}

function websocket_publication_node(): Node
{
    return Node::query()->firstOrCreate(['name' => 'websocket'], [
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.9',
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.9',
    ]);
}

/** @param list<RemoteCommand> $commands */
function websocket_publication_manager(
    array &$events,
    bool $failCertificate = false,
    ?NodeCaddyBuildException $buildFailure = null,
    array &$commands = [],
    ?Closure $onBuild = null,
): NativeWebSocketPublicationManager {
    $certificateDirectory = sys_get_temp_dir().'/orbit-websocket-test-'.bin2hex(random_bytes(4));
    mkdir($certificateDirectory);
    file_put_contents($certificateDirectory.'/reverb.pem', "CERT\n");
    file_put_contents($certificateDirectory.'/reverb.key', "KEY\n");

    return new NativeWebSocketPublicationManager(
        certificates: new class($events, $certificateDirectory) implements GatewayCertificateIssuer
        {
            public function __construct(private array &$events, private string $directory) {}

            public function issue(string $hostname, string $wireguardIp): GatewayCertificatePaths
            {
                $this->events[] = 'certificate:issue';

                return new GatewayCertificatePaths(
                    privateKeyPath: $this->directory.'/reverb.key',
                    certificatePath: $this->directory.'/reverb.pem',
                );
            }
        },
        certificatePublisher: new WebSocketCertificatePublisher,
        builds: new RecordingNodeCaddyBuilds($events, $buildFailure, $onBuild),
        dns: new class($events) implements PrivateDnsManager
        {
            public function __construct(private array &$events) {}

            public function converge(?Node $pendingNode = null): void
            {
                $this->events[] = $pendingNode instanceof Node ? 'dns:converge' : 'dns:converge-empty';
            }
        },
        ssh: new class($events, $failCertificate, $commands) implements SshExecutor
        {
            public function __construct(
                private array &$events,
                private bool $failCertificate,
                private array &$commands,
            ) {}

            public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
            {
                $this->commands[] = $command;

                if ($command->protectedInput !== null) {
                    $this->events[] = 'ssh:certificate';

                    return new CommandResult($this->failCertificate ? 1 : 0, '', '', 1, false);
                }

                $this->events[] = in_array('rm', $command->arguments, true) ? 'ssh:certificate-remove' : 'ssh:other';

                return new CommandResult(0, '', '', 1, false);
            }
        },
        keys: new class implements SshKeyProvider
        {
            public function privateKeyPath(): string
            {
                return '/tmp/orbit-test-key';
            }

            public function publicKey(): string
            {
                return 'ssh-ed25519 AAAA test';
            }
        },
        knownHosts: new class implements KnownHostsStore
        {
            public function path(): string
            {
                return '/tmp/orbit-test-known-hosts';
            }

            public function put(string $host, int $port, HostKey $key): void {}
        },
    );
}
