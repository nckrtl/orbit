<?php

declare(strict_types=1);

use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Certificates\GatewayCertificateIssuer;
use App\Domain\Certificates\GatewayCertificatePaths;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildException;
use App\Infrastructure\Nodes\CaddyPackageSourceProgram;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\ProxyCli\NativeProxyCliPublicationManager;
use App\Infrastructure\ProxyCli\ProxyCliCaddySiteRenderer;
use App\Infrastructure\ProxyCli\ProxyCliCertificatePublisher;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use Tests\Support\RecordingNodeCaddyBuilds;

it('installs Caddy, publishes the certificate, requests a Node Caddy build, then converges DNS last', function (): void {
    $events = [];
    $manager = proxycli_publication_manager($events);

    $manager->converge(proxycli_publication_node());

    expect($events)->toBe(['ssh:caddy-source', 'certificate:issue', 'ssh:certificate', 'build:beast', 'dns:converge']);
});

it('throws when the certificate SSH push fails', function (): void {
    $events = [];
    $manager = proxycli_publication_manager($events, failCertificate: true);

    expect(fn () => $manager->converge(proxycli_publication_node()))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('proxycli.certificate_publication_failed'));

    expect($events)->toBe(['ssh:caddy-source', 'certificate:issue', 'ssh:certificate']);
});

it('keeps its error code and names the Node, stage, and Caddy message when the build fails', function (): void {
    $events = [];
    $manager = proxycli_publication_manager($events, buildFailure: new NodeCaddyBuildException('beast', 'addresses', 'The build binds 192.168.6.30, which is not an address on this Node.'));

    expect(fn () => $manager->converge(proxycli_publication_node()))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('proxycli.caddy_publication_failed')
                ->and($exception->getMessage())->toContain('The build binds 192.168.6.30')
                ->and($exception->details)->toBe([
                    'node' => 'beast',
                    'stage' => 'addresses',
                    'message' => 'The build binds 192.168.6.30, which is not an address on this Node.',
                ]);
        });

    expect($events)->toBe(['ssh:caddy-source', 'certificate:issue', 'ssh:certificate', 'build:beast']);
});

it('builds the Node before it removes the certificate, then converges DNS without the node', function (): void {
    $events = [];
    $manager = proxycli_publication_manager($events);

    $manager->remove(proxycli_publication_node());

    expect($events)->toBe(['build:beast', 'ssh:certificate-remove', 'dns:converge-empty']);
});

it('renders a site that proxies collector.cli-proxy-api.orbit to the loopback collector and waits for its restart', function (): void {
    $site = new ProxyCliCaddySiteRenderer()->render(8787);

    expect($site)->toStartWith('# Managed by Orbit: proxycli')
        ->toContain("collector.cli-proxy-api.orbit {\n")
        ->not
        ->toMatch('/(?<!collector\.)cli-proxy-api\.orbit \{/')
        ->toContain('bind __ORBIT_PROXYCLI_BIND__')
        ->toContain('tls /etc/caddy/orbit-proxycli-cert-current/proxycli.pem /etc/caddy/orbit-proxycli-cert-current/proxycli.key')
        ->toContain("reverse_proxy 127.0.0.1:8787 {\n        lb_try_duration 5s\n    }");
});

function proxycli_publication_node(): Node
{
    return new Node([
        'name' => 'beast',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.8',
    ]);
}

function proxycli_publication_manager(
    array &$events,
    bool $failCertificate = false,
    ?NodeCaddyBuildException $buildFailure = null,
): NativeProxyCliPublicationManager {
    $certificateDirectory = sys_get_temp_dir().'/orbit-proxycli-test-'.bin2hex(random_bytes(4));
    mkdir($certificateDirectory);
    file_put_contents($certificateDirectory.'/proxycli.pem', "CERT\n");
    file_put_contents($certificateDirectory.'/proxycli.key', "KEY\n");

    return new NativeProxyCliPublicationManager(
        certificates: new class($events, $certificateDirectory) implements GatewayCertificateIssuer
        {
            public function __construct(private array &$events, private string $directory) {}

            public function issue(string $hostname, string $wireguardIp): GatewayCertificatePaths
            {
                $this->events[] = 'certificate:issue';

                return new GatewayCertificatePaths(
                    privateKeyPath: $this->directory.'/proxycli.key',
                    certificatePath: $this->directory.'/proxycli.pem',
                );
            }
        },
        certificatePublisher: new ProxyCliCertificatePublisher,
        builds: new RecordingNodeCaddyBuilds($events, $buildFailure),
        dns: new class($events) implements PrivateDnsManager
        {
            public function __construct(private array &$events) {}

            public function converge(?Node $pendingNode = null): void
            {
                $this->events[] = $pendingNode instanceof Node ? 'dns:converge' : 'dns:converge-empty';
            }
        },
        ssh: new class($events, $failCertificate) implements SshExecutor
        {
            public function __construct(
                private array &$events,
                private bool $failCertificate,
            ) {}

            public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
            {
                if (in_array(CaddyPackageSourceProgram::SOURCE_URI, $command->arguments, true)) {
                    $this->events[] = 'ssh:caddy-source';

                    return new CommandResult(0, '', '', 1, false);
                }

                if ($command->protectedInput !== null) {
                    $this->events[] = 'ssh:certificate';

                    return new CommandResult($this->failCertificate ? 1 : 0, '', '', 1, false);
                }

                if (in_array('rm', $command->arguments, true)) {
                    $this->events[] = 'ssh:certificate-remove';

                    return new CommandResult(0, '', '', 1, false);
                }

                $this->events[] = 'ssh:other';

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
