<?php

declare(strict_types=1);

use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Certificates\GatewayCertificateIssuer;
use App\Domain\Certificates\GatewayCertificatePaths;
use App\Domain\Nodes\NodeRoleOperationException;
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
use App\Models\Node;
use Tests\Support\RecordingNodeCaddyBuilds;

it('publishes the certificate, requests a Node Caddy build, then converges DNS last', function (): void {
    $events = [];
    $manager = websocket_publication_manager($events);

    $manager->converge(websocket_publication_node());

    expect($events)->toBe(['certificate:issue', 'ssh:certificate', 'build:websocket', 'dns:converge']);
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

    expect($events)->toBe(['certificate:issue', 'ssh:certificate']);
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

    expect($events)->toBe(['certificate:issue', 'ssh:certificate', 'build:websocket']);
});

it('builds the Node before it removes the certificate, then converges DNS without the node', function (): void {
    $events = [];
    $manager = websocket_publication_manager($events);

    $manager->remove(websocket_publication_node());

    expect($events)->toBe(['build:websocket', 'ssh:certificate-remove', 'dns:converge-empty']);
});

it('touches only the Gateway-side DNS record when the node is unreachable', function (): void {
    $events = [];
    $manager = websocket_publication_manager($events);

    $manager->removeUnreachable(websocket_publication_node());

    expect($events)->toBe(['dns:converge-empty']);
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

function websocket_publication_node(): Node
{
    return new Node([
        'name' => 'websocket',
        'public_ssh_port' => 22,
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
        builds: new RecordingNodeCaddyBuilds($events, $buildFailure),
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
