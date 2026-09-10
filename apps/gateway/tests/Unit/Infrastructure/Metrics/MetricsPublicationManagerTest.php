<?php

declare(strict_types=1);

use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Certificates\GatewayCertificateIssuer;
use App\Domain\Certificates\GatewayCertificatePaths;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Metrics\MetricsCaddyPublisher;
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

it('unloads Caddy before verifying isolation and publishes DNS last', function (): void {
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
        'process:caddy-withdraw',
        'ssh:status',
        'process:caddy-publish',
        'dns:metrics',
        'projection:leave',
    ]);
});

it('removes publication in DNS Caddy firewall certificate order', function (): void {
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
        'process:caddy-withdraw',
        'ssh:status',
        'ssh:delete',
        'ssh:delete',
        'ssh:status',
        'process:certificate',
        'projection:leave',
    ]);
});

it('keeps isolation and restores only a prior authorized route when publication fails', function (): void {
    $events = [];
    $previous = "# Managed by Orbit: metrics\n# Orbit Metrics authorization: 1\nmetrics.orbit { respond old }\n";
    $manager = metrics_publication_manager(
        $events,
        [metrics_publication_manager_result(stdout: metrics_publication_manager_firewall())],
        withdrawResults: [metrics_publication_manager_replaced($previous)],
        publishResults: [
            new CommandResult(1, '', 'private failure detail', 1, false),
            metrics_publication_manager_result(),
        ],
    );

    expect(fn () => $manager->converge(
        metrics_publication_manager_node('gateway', '10.44.0.1'),
        metrics_publication_manager_node('metrics', '10.44.0.3'),
    ))->toThrow(ResourceOperationException::class, 'Metrics Caddy publication did not complete.');

    expect($events)->toBe([
        'certificate:issue',
        'process:certificate',
        'process:caddy-withdraw',
        'ssh:status',
        'process:caddy-publish',
        'process:caddy-publish',
        'process:certificate',
    ])->not->toContain('ssh:delete');
});

it('never restores a legacy route after authorized publication fails', function (): void {
    $events = [];
    $manager = metrics_publication_manager(
        $events,
        [metrics_publication_manager_result(stdout: metrics_publication_manager_firewall())],
        withdrawResults: [
            metrics_publication_manager_result(),
            metrics_publication_manager_result(stdout: "orbit-metrics-publication:unchanged\n"),
        ],
        publishResults: [new CommandResult(1, '', 'private failure detail', 1, false)],
    );

    expect(fn () => $manager->converge(
        metrics_publication_manager_node('gateway', '10.44.0.1'),
        metrics_publication_manager_node('metrics', '10.44.0.3'),
    ))->toThrow(ResourceOperationException::class);

    expect($events)->toBe([
        'certificate:issue',
        'process:certificate',
        'process:caddy-withdraw',
        'ssh:status',
        'process:caddy-publish',
        'process:caddy-withdraw',
        'process:certificate',
    ]);
});

it('removes a new route but retains isolation when DNS publication fails', function (): void {
    $events = [];
    $manager = metrics_publication_manager(
        $events,
        [metrics_publication_manager_result(stdout: metrics_publication_manager_firewall())],
        withdrawResults: [
            metrics_publication_manager_result(stdout: "orbit-metrics-publication:unchanged\n"),
            metrics_publication_manager_result(stdout: "orbit-metrics-publication:unchanged\n"),
        ],
        failDns: true,
    );

    expect(fn () => $manager->converge(
        metrics_publication_manager_node('gateway', '10.44.0.1'),
        metrics_publication_manager_node('metrics', '10.44.0.3'),
    ))->toThrow(RuntimeException::class, 'DNS publication failed.');

    expect($events)->toBe([
        'certificate:issue',
        'process:certificate',
        'process:caddy-withdraw',
        'ssh:status',
        'process:caddy-publish',
        'dns:metrics',
        'process:caddy-withdraw',
        'process:certificate',
    ])->not->toContain('ssh:delete');
});

it('retains projection ownership through rollback', function (): void {
    $events = [];
    $manager = metrics_publication_manager(
        $events,
        [metrics_publication_manager_result(stdout: metrics_publication_manager_firewall())],
        withdrawResults: [
            metrics_publication_manager_result(stdout: "orbit-metrics-publication:unchanged\n"),
            metrics_publication_manager_result(stdout: "orbit-metrics-publication:unchanged\n"),
        ],
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
        ->toBe('projection:leave');
});

/**
 * @param  list<string>  $events
 * @param  list<CommandResult>  $sshResults
 * @param  list<CommandResult>  $withdrawResults
 * @param  list<CommandResult>  $publishResults
 */
function metrics_publication_manager(
    array &$events,
    array $sshResults,
    array $withdrawResults = [],
    array $publishResults = [],
    bool $failDns = false,
    ?DevelopmentProjectionOperationLock $projection = null,
): MetricsPublicationManager {
    $processes = new MetricsPublicationManagerProcessRunner(
        $events,
        $withdrawResults,
        $publishResults,
    );

    return new MetricsPublicationManager(
        certificates: new MetricsPublicationManagerCertificateIssuer($events),
        certificatePublisher: new MetricsCertificatePublisher($processes),
        caddy: new MetricsCaddyPublisher($processes),
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
    return new Node([
        'name' => $name,
        'wireguard_ip' => $address,
        'user' => 'orbit',
    ]);
}

function metrics_publication_manager_result(string $stdout = "orbit-metrics-publication:created\n"): CommandResult
{
    return new CommandResult(0, $stdout, '', 1, false);
}

function metrics_publication_manager_replaced(string $previous): CommandResult
{
    return metrics_publication_manager_result(
        'orbit-metrics-publication:replaced:'.base64_encode($previous)."\n",
    );
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
     * @param  list<CommandResult>  $withdrawResults
     * @param  list<CommandResult>  $publishResults
     */
    public function __construct(
        private array &$events,
        private array $withdrawResults,
        private array $publishResults,
    ) {}

    public function run(ProcessInvocation $invocation): CommandResult
    {
        $input = (string) $invocation->input;

        if (str_contains($input, 'orbit-metrics-cert-versions')) {
            $this->events[] = 'process:certificate';

            return metrics_publication_manager_result();
        }

        if (str_contains($input, "sed -n '2p'")) {
            $this->events[] = 'process:caddy-withdraw';

            return array_shift($this->withdrawResults)
                ?? metrics_publication_manager_result(stdout: "orbit-metrics-publication:unchanged\n");
        }

        $this->events[] = 'process:caddy-publish';

        return array_shift($this->publishResults) ?? metrics_publication_manager_result();
    }
}

final class MetricsPublicationManagerCertificateIssuer implements GatewayCertificateIssuer
{
    /** @param list<string> $events */
    public function __construct(
        private array &$events,
    ) {}

    public function issue(string $hostname, string $wireguardIp): GatewayCertificatePaths
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
