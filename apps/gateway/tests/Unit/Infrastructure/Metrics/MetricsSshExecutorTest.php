<?php

declare(strict_types=1);

use App\Infrastructure\Metrics\MetricsConfigurationSnapshot;
use App\Infrastructure\Metrics\MetricsRuntimeSpec;
use App\Infrastructure\Metrics\MetricsService;
use App\Infrastructure\Metrics\MetricsSshExecutor;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;

describe(MetricsSshExecutor::class, function (): void {
    it('transports active and pending Grafana credentials only through protected input', function (): void {
        $ssh = new MetricsCapturingSshExecutor([
            metricsCommandResult(),
            metricsCommandResult(),
        ]);
        $executor = metricsSshExecutor($ssh);
        $node = metricsSshNode();

        $executor->apply($node, 'active-credential-sentinel', 'pending-credential-sentinel');
        $verified = $executor->verify($node, 'pending-credential-sentinel');

        expect($verified)->toBeTrue()->and($ssh->commands)->toHaveCount(2);

        foreach ($ssh->commands as $command) {
            expect($command->shellCommand())
                ->not->toContain('active-credential-sentinel')
                ->not->toContain('pending-credential-sentinel')->and($command->protectedInput)
                ->not->toBeNull()->and(var_export($command, true))
                ->not->toContain('active-credential-sentinel')
                ->not->toContain('pending-credential-sentinel');
        }
    });

    it('refuses a foreign container before any destructive runtime command', function (): void {
        $foreignLabels = json_encode(['com.orbit.managed' => 'someone-else'], JSON_THROW_ON_ERROR)."\n";
        $ssh = new MetricsCapturingSshExecutor([
            metricsCommandResult(stdout: json_encode([
                'com.orbit.managed' => 'metrics',
                'com.orbit.metrics.network' => 'runtime',
            ], JSON_THROW_ON_ERROR)
                ."\n"),
            metricsCommandResult(stdout: $foreignLabels),
        ]);
        $executor = metricsSshExecutor($ssh);
        $spec = new MetricsRuntimeSpec()->for(
            MetricsService::Prometheus,
            41,
            '10.44.0.3',
            'configuration',
        );

        expect(fn () => $executor->convergeContainers(metricsSshNode(), [$spec]))
            ->toThrow(RuntimeException::class, 'foreign Metrics container')
            ->and(array_map(
                static fn (RemoteCommand $command): string => $command->shellCommand(),
                $ssh->commands,
            ))
            ->not->toContain("'docker' 'container' 'rm'");
    });

    it('uses one proven private Docker network for both Metrics containers', function (): void {
        $prometheus = new MetricsRuntimeSpec()->for(
            MetricsService::Prometheus,
            41,
            '10.44.0.3',
            'configuration',
        );
        $grafana = new MetricsRuntimeSpec()->for(
            MetricsService::Grafana,
            41,
            '10.44.0.3',
            'configuration',
        );
        $absent = [metricsCommandResult(exitCode: 1), metricsCommandResult()];
        $ssh = new MetricsCapturingSshExecutor([
            ...$absent,
            ...$absent,
            ...$absent,
            ...$absent,
            ...$absent,
            ...$absent,
            ...$absent,
            metricsCommandResult(),
            metricsCommandResult(),
            metricsCommandResult(),
            metricsCommandResult(stdout: "healthy\n"),
            metricsCommandResult(),
            metricsCommandResult(),
            metricsCommandResult(stdout: "healthy\n"),
        ]);

        metricsSshExecutor($ssh)->convergeContainers(
            metricsSshNode(),
            [$prometheus, $grafana],
        );

        $commands = array_map(
            static fn (RemoteCommand $command): string => $command->shellCommand(),
            $ssh->commands,
        );
        $containerRuns = array_values(array_filter(
            $commands,
            static fn (string $command): bool => str_contains($command, "'docker' 'container' 'run'"),
        ));
        $networkCreates = array_values(array_filter(
            $commands,
            static fn (string $command): bool => str_contains($command, "'docker' 'network' 'create'"),
        ));

        expect($networkCreates)
            ->toHaveCount(1)
            ->and($networkCreates[0])
            ->toContain("'com.orbit.metrics.network=runtime'")
            ->and($containerRuns)
            ->toHaveCount(2);

        foreach ($containerRuns as $command) {
            expect($command)->toContain("'--network' 'orbit-metrics-runtime'");
        }
    });

    it('removes a proven recovery container before removing the runtime network', function (): void {
        $spec = new MetricsRuntimeSpec()->for(
            MetricsService::Prometheus,
            41,
            '10.44.0.3',
            'configuration',
        );
        $ssh = new MetricsCapturingSshExecutor([
            metricsCommandResult(stdout: json_encode($spec->networkLabels, JSON_THROW_ON_ERROR)."\n"),
            metricsCommandResult(exitCode: 1),
            metricsCommandResult(),
            metricsCommandResult(stdout: json_encode($spec->labels, JSON_THROW_ON_ERROR)."\n"),
            metricsCommandResult(),
            metricsCommandResult(),
        ]);

        metricsSshExecutor($ssh)->removeContainers(metricsSshNode(), [$spec]);

        $commands = array_map(
            static fn (RemoteCommand $command): string => $command->shellCommand(),
            $ssh->commands,
        );
        expect($commands)
            ->toContain("'docker' 'container' 'rm' '--force' '--' 'orbit-metrics-prometheus-orbit-rollback'")
            ->and(array_key_last($commands))
            ->not
            ->toBeNull()
            ->and($commands[array_key_last($commands)])
            ->toContain("'docker' 'network' 'rm' '--' 'orbit-metrics-runtime'");
    });

    it('removes all task-owned configuration artifacts after first-convergence rollback', function (): void {
        $ssh = new MetricsCapturingSshExecutor([]);
        $executor = metricsSshExecutor($ssh);

        $executor->restoreConfiguration(
            metricsSshNode(),
            new MetricsConfigurationSnapshot(directoryExisted: false, files: []),
        );

        $commands = array_map(
            static fn (RemoteCommand $command): string => $command->shellCommand(),
            $ssh->commands,
        );

        expect($commands)
            ->toContain("'sudo' 'rm' '-f' '--' '/etc/orbit/metrics/.orbit-owner'")
            ->toContain("'sudo' 'rm' '-f' '--' '/etc/orbit/metrics/prometheus.yml.orbit-candidate'")
            ->toContain("'sudo' 'rmdir' '--ignore-fail-on-non-empty' '--' '/etc/orbit/metrics'");
    });

    it('reports a rollback failure when a created container cannot be removed', function (): void {
        $spec = new MetricsRuntimeSpec()->for(
            MetricsService::Prometheus,
            41,
            '10.44.0.3',
            'configuration',
        );
        $ssh = new MetricsCapturingSshExecutor([
            metricsCommandResult(exitCode: 1),
            metricsCommandResult(),
            metricsCommandResult(exitCode: 1),
            metricsCommandResult(),
            metricsCommandResult(exitCode: 1),
            metricsCommandResult(),
            metricsCommandResult(exitCode: 1),
            metricsCommandResult(),
            metricsCommandResult(),
            metricsCommandResult(),
            metricsCommandResult(),
            metricsCommandResult(stdout: "unhealthy\n"),
            metricsCommandResult(stdout: json_encode($spec->labels, JSON_THROW_ON_ERROR)."\n"),
            metricsCommandResult(exitCode: 1),
        ]);
        $executor = metricsSshExecutor($ssh);

        try {
            $executor->convergeContainers(metricsSshNode(), [$spec]);
            test()->fail('Expected container rollback to fail closed.');
        } catch (\App\Domain\Shared\ResourceOperationException $exception) {
            expect($exception->errorCode)->toBe('metrics.container_rollback_failed');
        }
    });
});

function metricsSshExecutor(MetricsCapturingSshExecutor $ssh): MetricsSshExecutor
{
    return new MetricsSshExecutor(
        ssh: $ssh,
        keys: new MetricsSshKeyProviderFake,
        knownHosts: new MetricsKnownHostsStoreFake,
    );
}

function metricsSshNode(): Node
{
    $node = new Node([
        'name' => 'metrics-ssh',
        'wireguard_address' => '10.44.0.3',
    ]);
    $node->id = 3;

    return $node;
}

function metricsCommandResult(int $exitCode = 0, string $stdout = ''): CommandResult
{
    return new CommandResult($exitCode, $stdout, '', 1, false);
}

final class MetricsCapturingSshExecutor implements SshExecutor
{
    /** @var list<RemoteCommand> */
    public array $commands = [];

    /** @param list<CommandResult> $results */
    public function __construct(
        private array $results,
    ) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->commands[] = $command;

        return array_shift($this->results) ?? metricsCommandResult();
    }
}

final readonly class MetricsSshKeyProviderFake implements SshKeyProvider
{
    public function privateKeyPath(): string
    {
        return '/tmp/metrics-key';
    }

    public function publicKey(): string
    {
        return 'ssh-ed25519 metrics-key';
    }
}

final readonly class MetricsKnownHostsStoreFake implements KnownHostsStore
{
    public function path(): string
    {
        return '/tmp/metrics-known-hosts';
    }

    public function put(string $host, int $port, HostKey $key): void {}
}
