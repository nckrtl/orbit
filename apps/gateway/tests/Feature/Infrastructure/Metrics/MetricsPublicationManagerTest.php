<?php

declare(strict_types=1);

use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Certificates\GatewayCertificateIssuer;
use App\Domain\Certificates\GatewayCertificatePaths;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Caddy\Build\CaddySiteCertificates;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildException;
use App\Infrastructure\Metrics\MetricsCertificatePublisher;
use App\Infrastructure\Metrics\MetricsPublicationManager;
use App\Infrastructure\Metrics\MetricsPublicationSshExecutor;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use Tests\Support\RecordingNodeCaddyBuilds;

it('publishes the certificate and firewall, requests a Gateway build, and publishes DNS last', function (): void {
    $events = [];
    $manager = metrics_publication_manager(
        $events,
        [metrics_publication_manager_result(stdout: metrics_publication_manager_firewall())],
        projection: new MetricsPublicationManagerProjectionOwner($events),
    );

    $manager->converge(
        metrics_publication_manager_node('gateway', '10.44.0.1'),
        metrics_publication_manager_node('metrics', '10.44.0.3'),
    );

    expect($events)->toBe([
        'projection:enter',
        'certificate:issue',
        'process:certificate',
        'ssh:status',
        'build:gateway',
        'dns:metrics',
        'projection:leave',
    ])
        ->and(new CaddySiteCertificates()->published(metrics_publication_manager_node('gateway', '10.44.0.1')->id, CaddySiteCertificates::Metrics))->toBeTrue();
});

it('writes no Caddy file on the Gateway itself', function (): void {
    $events = [];
    $invocations = [];
    $manager = metrics_publication_manager(
        $events,
        [metrics_publication_manager_result(stdout: metrics_publication_manager_firewall())],
        invocations: $invocations,
    );

    $manager->converge(
        metrics_publication_manager_node('gateway', '10.44.0.1'),
        metrics_publication_manager_node('metrics', '10.44.0.3'),
    );

    expect($invocations)->not->toBeEmpty();

    foreach ($invocations as $invocation) {
        expect(implode(' ', $invocation->arguments)."\n".($invocation->input ?? ''))
            ->not->toContain('orbit-versions')
            ->not->toContain('metrics.caddy');
    }
});

it('keeps its error code, names the build failure, and keeps the certificate a failed role still names', function (): void {
    $events = [];
    $manager = metrics_publication_manager(
        $events,
        [metrics_publication_manager_result(stdout: metrics_publication_manager_firewall())],
        buildFailure: new NodeCaddyBuildException('gateway', 'reload', 'Error: loading new config'),
    );

    expect(fn () => $manager->converge(
        metrics_publication_manager_node('gateway', '10.44.0.1'),
        metrics_publication_manager_node('metrics', '10.44.0.3'),
    ))->toThrow(function (ResourceOperationException $exception): void {
        expect($exception->errorCode)->toBe('metrics.caddy_publication_failed')
            ->and($exception->details)->toBe(['node' => 'gateway', 'stage' => 'reload', 'message' => 'Error: loading new config']);
    });

    expect($events)->toBe(['certificate:issue', 'process:certificate', 'ssh:status', 'build:gateway']);
});

it('removes publication in DNS, build, firewall, certificate order', function (): void {
    $events = [];
    $manager = metrics_publication_manager(
        $events,
        [
            metrics_publication_manager_result(stdout: metrics_publication_manager_firewall()),
            metrics_publication_manager_result(),
            metrics_publication_manager_result(),
            metrics_publication_manager_result(stdout: metrics_publication_manager_wireguard_firewall()),
        ],
        projection: new MetricsPublicationManagerProjectionOwner($events),
    );

    $manager->remove(
        metrics_publication_manager_node('gateway', '10.44.0.1'),
        metrics_publication_manager_node('metrics', '10.44.0.3'),
    );

    expect($events)->toBe([
        'projection:enter',
        'dns:none',
        'build:gateway',
        'ssh:status',
        'ssh:delete',
        'ssh:delete',
        'ssh:status',
        'process:certificate',
        'projection:leave',
    ]);
});

it('keeps the certificate when the Metrics role moved and its site still renders', function (): void {
    new CaddySiteCertificates()->record(metrics_publication_manager_node('gateway', '10.44.0.1')->id, CaddySiteCertificates::Metrics);
    $target = metrics_publication_manager_node('metrics-next', '10.44.0.4');
    $target->roles()->create(['role' => RoleName::Metrics, 'status' => LifecycleStatus::Active]);
    $events = [];
    $manager = metrics_publication_manager(
        $events,
        [
            metrics_publication_manager_result(stdout: metrics_publication_manager_firewall()),
            metrics_publication_manager_result(),
            metrics_publication_manager_result(),
            metrics_publication_manager_result(stdout: metrics_publication_manager_wireguard_firewall()),
        ],
    );

    $manager->remove(
        metrics_publication_manager_node('gateway', '10.44.0.1'),
        metrics_publication_manager_node('metrics', '10.44.0.3'),
    );

    expect($events)->toBe(['dns:none', 'build:gateway', 'ssh:status', 'ssh:delete', 'ssh:delete', 'ssh:status'])
        ->and(new CaddySiteCertificates()->published(metrics_publication_manager_node('gateway', '10.44.0.1')->id, CaddySiteCertificates::Metrics))->toBeTrue();
});

it('retracts the Gateway side by building the Gateway before it removes the certificate', function (): void {
    $gateway = metrics_publication_manager_node('gateway', '10.44.0.1');
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    new CaddySiteCertificates()->record($gateway->id, CaddySiteCertificates::Metrics);
    $events = [];

    metrics_publication_manager($events, [])->retract(metrics_publication_manager_node('metrics', '10.44.0.3'));

    expect($events)->toBe(['dns:none', 'build:gateway', 'process:certificate'])
        ->and(new CaddySiteCertificates()->published($gateway->id, CaddySiteCertificates::Metrics))->toBeFalse();
});

it('retains projection ownership when DNS publication fails', function (): void {
    $events = [];
    $manager = metrics_publication_manager(
        $events,
        [metrics_publication_manager_result(stdout: metrics_publication_manager_firewall())],
        failDns: true,
        projection: new MetricsPublicationManagerProjectionOwner($events),
    );

    expect(fn () => $manager->converge(
        metrics_publication_manager_node('gateway', '10.44.0.1'),
        metrics_publication_manager_node('metrics', '10.44.0.3'),
    ))->toThrow(RuntimeException::class, 'DNS publication failed.');

    expect($events[0])
        ->toBe('projection:enter')
        ->and($events[array_key_last($events)])
        ->toBe('projection:leave')
        ->and($events)->not->toContain('ssh:delete');
});

/**
 * @param  list<string>  $events
 * @param  list<CommandResult>  $sshResults
 * @param  list<ProcessInvocation>  $invocations
 */
function metrics_publication_manager(
    array &$events,
    array $sshResults,
    ?NodeCaddyBuildException $buildFailure = null,
    bool $failDns = false,
    ?DevelopmentProjectionOperationLock $projection = null,
    array &$invocations = [],
): MetricsPublicationManager {
    $processes = new MetricsPublicationManagerProcessRunner($events, $invocations);

    return new MetricsPublicationManager(
        certificates: new MetricsPublicationManagerCertificateIssuer($events),
        certificatePublisher: new MetricsCertificatePublisher($processes),
        builds: new RecordingNodeCaddyBuilds($events, $buildFailure),
        firewall: new MetricsPublicationSshExecutor(
            new MetricsPublicationManagerSshExecutor($events, $sshResults),
            new MetricsPublicationManagerSshKeyProvider,
            new MetricsPublicationManagerKnownHostsStore,
        ),
        dns: new MetricsPublicationManagerDns($events, $failDns),
        projection: $projection ?? new MetricsPublicationManagerProjectionOwner,
    );
}

function metrics_publication_manager_node(string $name, string $address): Node
{
    return Node::query()->firstOrCreate(['name' => $name], [
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => "{$name}.example.test",
        'wireguard_ip' => $address,
        'user' => 'orbit',
    ]);
}

function metrics_publication_manager_result(string $stdout = "orbit-metrics-publication:created\n"): CommandResult
{
    return new CommandResult(0, $stdout, '', 1, false);
}

function metrics_publication_manager_firewall(): string
{
    return <<<'STATUS'
        Status: active

        [ 1] 10.44.0.3 3000/tcp on orbit ALLOW IN 10.44.0.1 # orbit:metrics-grafana-upstream
        [ 2] 10.44.0.3 3000/tcp on orbit DENY IN Anywhere # orbit:metrics-grafana-isolation
        [ 3] 10.44.0.3 on orbit ALLOW IN Anywhere on orbit # orbit:wireguard-members
        STATUS;
}

function metrics_publication_manager_wireguard_firewall(): string
{
    return <<<'STATUS'
        Status: active

        [ 1] 10.44.0.3 on orbit ALLOW IN Anywhere on orbit # orbit:wireguard-members
        STATUS;
}

final class MetricsPublicationManagerProcessRunner implements ProcessRunner
{
    /**
     * @param  list<string>  $events
     * @param  list<ProcessInvocation>  $invocations
     */
    public function __construct(
        private array &$events,
        private array &$invocations,
    ) {}

    public function run(ProcessInvocation $invocation): CommandResult
    {
        $this->invocations[] = $invocation;
        $this->events[] = str_contains((string) $invocation->input, 'orbit-metrics-cert') ? 'process:certificate' : 'process:other';

        return metrics_publication_manager_result();
    }
}

final class MetricsPublicationManagerCertificateIssuer implements GatewayCertificateIssuer
{
    /** @param list<string> $events */
    public function __construct(
        private array &$events,
    ) {}

    public function issue(string $domain, string $wireguardIp): GatewayCertificatePaths
    {
        $this->events[] = 'certificate:issue';

        return new GatewayCertificatePaths('/ca/metrics.key', '/ca/metrics.pem');
    }
}

final class MetricsPublicationManagerSshExecutor implements SshExecutor
{
    /** @param list<string> $events @param list<CommandResult> $results */
    public function __construct(
        private array &$events,
        private array $results,
    ) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $arguments = $command->arguments;
        $this->events[] = match (true) {
            in_array('status', $arguments, true) => 'ssh:status',
            in_array('insert', $arguments, true) => 'ssh:insert',
            in_array('delete', $arguments, true) => 'ssh:delete',
            default => 'ssh:unknown',
        };

        return array_shift($this->results) ?? metrics_publication_manager_result(stdout: '');
    }
}

final readonly class MetricsPublicationManagerSshKeyProvider implements SshKeyProvider
{
    public function privateKeyPath(): string
    {
        return '/tmp/key';
    }

    public function publicKey(): string
    {
        return 'ssh-ed25519 key';
    }
}

final readonly class MetricsPublicationManagerKnownHostsStore implements KnownHostsStore
{
    public function path(): string
    {
        return '/tmp/known-hosts';
    }

    public function put(string $host, int $port, HostKey $key): void {}
}

final class MetricsPublicationManagerDns implements PrivateDnsManager
{
    /** @param list<string> $events */
    public function __construct(
        private array &$events,
        private bool $fail,
    ) {}

    public function converge(?Node $pendingNode = null): void
    {
        $this->events[] = 'dns:'.($pendingNode?->name ?? 'none');

        if ($this->fail) {
            throw new RuntimeException('DNS publication failed.');
        }
    }
}

final class MetricsPublicationManagerProjectionOwner implements DevelopmentProjectionOperationLock
{
    /** @var list<string>|null */
    private ?array $events;

    /** @param list<string>|null $events */
    public function __construct(?array &$events = null)
    {
        $this->events = &$events;
    }

    public function run(Closure $operation): mixed
    {
        if (is_array($this->events)) {
            $this->events[] = 'projection:enter';
        }

        try {
            return $operation();
        } finally {
            if (is_array($this->events)) {
                $this->events[] = 'projection:leave';
            }
        }
    }
}
