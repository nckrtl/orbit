<?php

declare(strict_types=1);

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

it('publishes Metrics in certificate firewall Caddy and DNS order', function (): void {
    $events = [];
    $manager = metrics_publication_manager(
        $events,
        [
            metrics_publication_manager_result(stdout: "Status: active\n"),
            metrics_publication_manager_result(),
            metrics_publication_manager_result(stdout: metrics_publication_manager_firewall()),
        ],
    );

    $manager->converge(
        metrics_publication_manager_node('gateway', '10.44.0.1'),
        metrics_publication_manager_node('metrics', '10.44.0.3'),
    );

    expect($events)->toBe([
        'certificate:issue',
        'process:certificate',
        'ssh:status',
        'ssh:apply',
        'ssh:status',
        'process:caddy',
        'dns:metrics',
    ]);
});

it('removes Metrics publication in exact reverse order', function (): void {
    $events = [];
    $manager = metrics_publication_manager(
        $events,
        [
            metrics_publication_manager_result(stdout: metrics_publication_manager_firewall()),
            metrics_publication_manager_result(),
            metrics_publication_manager_result(stdout: "Status: active\n"),
        ],
    );

    $manager->remove(
        metrics_publication_manager_node('gateway', '10.44.0.1'),
        metrics_publication_manager_node('metrics', '10.44.0.3'),
    );

    expect($events)->toBe([
        'dns:none',
        'process:caddy',
        'ssh:status',
        'ssh:delete',
        'ssh:status',
        'process:certificate',
    ]);
});

it('rolls completed publication stages back when Caddy publication fails', function (): void {
    $events = [];
    $manager = metrics_publication_manager(
        $events,
        [
            metrics_publication_manager_result(stdout: "Status: active\n"),
            metrics_publication_manager_result(),
            metrics_publication_manager_result(stdout: metrics_publication_manager_firewall()),
            metrics_publication_manager_result(stdout: metrics_publication_manager_firewall()),
            metrics_publication_manager_result(),
            metrics_publication_manager_result(stdout: "Status: active\n"),
        ],
        failCaddy: true,
    );

    expect(fn () => $manager->converge(
        metrics_publication_manager_node('gateway', '10.44.0.1'),
        metrics_publication_manager_node('metrics', '10.44.0.3'),
    ))
        ->toThrow(ResourceOperationException::class, 'Metrics Caddy publication did not complete.');
    expect($events)->toBe([
        'certificate:issue',
        'process:certificate',
        'ssh:status',
        'ssh:apply',
        'ssh:status',
        'process:caddy',
        'ssh:status',
        'ssh:delete',
        'ssh:status',
        'process:certificate',
    ]);
});

it('preserves pre-existing healthy publication when repeated convergence fails', function (): void {
    $events = [];
    $manager = metrics_publication_manager(
        $events,
        [
            metrics_publication_manager_result(stdout: metrics_publication_manager_firewall()),
        ],
        failCaddy: true,
        certificateChanged: false,
    );

    expect(fn () => $manager->converge(
        metrics_publication_manager_node('gateway', '10.44.0.1'),
        metrics_publication_manager_node('metrics', '10.44.0.3'),
    ))
        ->toThrow(ResourceOperationException::class, 'Metrics Caddy publication did not complete.');
    expect($events)->toBe([
        'certificate:issue',
        'process:certificate',
        'ssh:status',
        'process:caddy',
    ]);
});

it('removes newly created publications in reverse order when DNS publication fails', function (): void {
    $events = [];
    $manager = metrics_publication_manager(
        $events,
        [
            metrics_publication_manager_result(stdout: "Status: active\n"),
            metrics_publication_manager_result(),
            metrics_publication_manager_result(stdout: metrics_publication_manager_firewall()),
            metrics_publication_manager_result(stdout: metrics_publication_manager_firewall()),
            metrics_publication_manager_result(),
            metrics_publication_manager_result(stdout: "Status: active\n"),
        ],
        failDns: true,
    );

    expect(fn () => $manager->converge(
        metrics_publication_manager_node('gateway', '10.44.0.1'),
        metrics_publication_manager_node('metrics', '10.44.0.3'),
    ))
        ->toThrow(RuntimeException::class, 'DNS publication failed.');
    expect($events)->toBe([
        'certificate:issue',
        'process:certificate',
        'ssh:status',
        'ssh:apply',
        'ssh:status',
        'process:caddy',
        'dns:metrics',
        'process:caddy',
        'ssh:status',
        'ssh:delete',
        'ssh:status',
        'process:certificate',
    ]);
});

it('restores replaced Caddy and certificate publications when DNS publication fails', function (): void {
    $events = [];
    $manager = metrics_publication_manager(
        $events,
        [metrics_publication_manager_result(stdout: metrics_publication_manager_firewall())],
        failDns: true,
        previousCertificateTarget: '/etc/caddy/orbit-metrics-cert-versions/previous',
        previousCaddyConfiguration: "# Managed by Orbit: metrics\nold metrics\n",
    );

    expect(fn () => $manager->converge(
        metrics_publication_manager_node('gateway', '10.44.0.1'),
        metrics_publication_manager_node('metrics', '10.44.0.3'),
    ))
        ->toThrow(RuntimeException::class, 'DNS publication failed.');
    expect($events)->toBe([
        'certificate:issue',
        'process:certificate',
        'ssh:status',
        'process:caddy',
        'dns:metrics',
        'process:caddy',
        'process:certificate',
    ]);
});

it('restores a renewed certificate after an unchanged Caddy publication when DNS fails', function (): void {
    $events = [];
    $manager = metrics_publication_manager(
        $events,
        [metrics_publication_manager_result(stdout: metrics_publication_manager_firewall())],
        caddyChanged: false,
        failDns: true,
        previousCertificateTarget: '/etc/caddy/orbit-metrics-cert-versions/previous',
    );

    expect(fn () => $manager->converge(
        metrics_publication_manager_node('gateway', '10.44.0.1'),
        metrics_publication_manager_node('metrics', '10.44.0.3'),
    ))
        ->toThrow(RuntimeException::class, 'DNS publication failed.');
    expect($events)->toBe([
        'certificate:issue',
        'process:certificate',
        'ssh:status',
        'process:caddy',
        'dns:metrics',
        'process:certificate',
    ]);
});

it('retracts only the Gateway side of the publication, never touching the firewall', function (): void {
    $events = [];
    $manager = metrics_publication_manager($events, []);

    $manager->retract(metrics_publication_manager_node('metrics', '10.44.0.3'));

    expect($events)->toBe([
        'dns:none',
        'process:caddy',
        'process:certificate',
    ]);
});

/** @param list<string> $events @param list<CommandResult> $sshResults */
/** @mago-expect lint:excessive-parameter-list Named failure controls keep each rollback scenario explicit. */
function metrics_publication_manager(
    array &$events,
    array $sshResults,
    bool $failCaddy = false,
    bool $certificateChanged = true,
    bool $caddyChanged = true,
    bool $failDns = false,
    ?string $previousCertificateTarget = null,
    ?string $previousCaddyConfiguration = null,
): MetricsPublicationManager {
    $processes = new MetricsPublicationManagerProcessRunner(
        $events,
        $failCaddy,
        $certificateChanged,
        $caddyChanged,
        $previousCertificateTarget,
        $previousCaddyConfiguration,
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

function metrics_publication_manager_result(string $stdout = ''): CommandResult
{
    return new CommandResult(0, $stdout, '', 1, false);
}

function metrics_publication_manager_firewall(): string
{
    return <<<'STATUS'
        Status: active

        [ 7] 10.44.0.3 3000/tcp on orbit ALLOW IN 10.44.0.1 # orbit:metrics-grafana-upstream
        STATUS;
}

final class MetricsPublicationManagerProcessRunner implements ProcessRunner
{
    /** @param list<string> $events */
    /** @mago-expect lint:excessive-parameter-list The test runner models each independent publication outcome. */
    public function __construct(
        private array &$events,
        private bool $failCaddy,
        private bool $certificateChanged,
        private bool $caddyChanged,
        private ?string $previousCertificateTarget,
        private ?string $previousCaddyConfiguration,
    ) {}

    public function run(ProcessInvocation $invocation): CommandResult
    {
        $event = str_contains((string) $invocation->input, 'orbit-metrics-cert-versions')
            ? 'process:certificate'
            : 'process:caddy';
        $this->events[] = $event;

        if ($this->failCaddy && $event === 'process:caddy') {
            return new CommandResult(1, '', 'private failure detail', 1, false);
        }

        $changed = $event === 'process:certificate' ? $this->certificateChanged : $this->caddyChanged;
        $previous = $event === 'process:certificate'
            ? $this->previousCertificateTarget
            : $this->previousCaddyConfiguration;
        $change = is_string($previous)
            ? 'replaced:'.base64_encode($previous)
            : 'created';

        return metrics_publication_manager_result(
            stdout: 'orbit-metrics-publication:'.($changed ? $change : 'unchanged')."\n",
        );
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
            in_array('allow', $arguments, true) => 'ssh:apply',
            in_array('delete', $arguments, true) => 'ssh:delete',
            default => 'ssh:unknown',
        };

        return array_shift($this->results) ?? metrics_publication_manager_result();
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
