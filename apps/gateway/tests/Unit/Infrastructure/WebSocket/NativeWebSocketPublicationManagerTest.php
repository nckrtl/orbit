<?php

declare(strict_types=1);

use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Certificates\GatewayCertificateIssuer;
use App\Domain\Certificates\GatewayCertificatePaths;
use App\Domain\Nodes\NodeRoleOperationException;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\WebSocket\NativeWebSocketPublicationManager;
use App\Infrastructure\WebSocket\WebSocketCaddyPublisher;
use App\Infrastructure\WebSocket\WebSocketCaddySiteRenderer;
use App\Infrastructure\WebSocket\WebSocketCertificatePublisher;
use App\Models\Node;

it('issues the certificate, publishes the Caddy site, then converges DNS last', function (): void {
    $events = [];
    $manager = websocket_publication_manager($events);

    $manager->converge(websocket_publication_node());

    expect($events)->toBe(['certificate:issue', 'ssh:certificate', 'ssh:caddy', 'dns:converge']);
});

it('throws when the certificate SSH push fails', function (): void {
    $events = [];
    $manager = websocket_publication_manager($events, failCertificate: true);

    expect(fn () => $manager->converge(websocket_publication_node()))
        ->toThrow(NodeRoleOperationException::class);

    expect($events)->toBe(['certificate:issue', 'ssh:certificate']);
});

it('throws when the Caddy SSH push fails', function (): void {
    $events = [];
    $manager = websocket_publication_manager($events, failCaddy: true);

    expect(fn () => $manager->converge(websocket_publication_node()))
        ->toThrow(NodeRoleOperationException::class);

    expect($events)->toBe(['certificate:issue', 'ssh:certificate', 'ssh:caddy']);
});

it('removes the Caddy site and certificate over SSH, then converges DNS without the node', function (): void {
    $events = [];
    $manager = websocket_publication_manager($events);

    $manager->remove(websocket_publication_node());

    expect($events)->toBe(['ssh:caddy-remove', 'ssh:certificate-remove', 'dns:converge-empty']);
});

it('touches only the Gateway-side DNS record when the node is unreachable', function (): void {
    $events = [];
    $manager = websocket_publication_manager($events);

    $manager->removeUnreachable(websocket_publication_node());

    expect($events)->toBe(['dns:converge-empty']);
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

function websocket_publication_manager(
    array &$events,
    bool $failCertificate = false,
    bool $failCaddy = false,
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
        caddy: new WebSocketCaddyPublisher,
        site: new WebSocketCaddySiteRenderer,
        dns: new class($events) implements PrivateDnsManager
        {
            public function __construct(private array &$events) {}

            public function converge(?Node $pendingNode = null): void
            {
                $this->events[] = $pendingNode instanceof Node ? 'dns:converge' : 'dns:converge-empty';
            }
        },
        ssh: new class($events, $failCertificate, $failCaddy) implements SshExecutor
        {
            public function __construct(
                private array &$events,
                private bool $failCertificate,
                private bool $failCaddy,
            ) {}

            public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
            {
                if ($command->protectedInput !== null) {
                    $this->events[] = 'ssh:certificate';

                    return new CommandResult($this->failCertificate ? 1 : 0, '', '', 1, false);
                }

                if (in_array('rm', $command->arguments, true)) {
                    $this->events[] = 'ssh:certificate-remove';

                    return new CommandResult($this->failCertificate ? 1 : 0, '', '', 1, false);
                }

                $isCaddyPublish = str_contains($command->input ?? '', 'bind_address');
                $this->events[] = $isCaddyPublish ? 'ssh:caddy' : 'ssh:caddy-remove';

                return new CommandResult($this->failCaddy ? 1 : 0, '', '', 1, false);
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
        port: 8790,
    );
}
