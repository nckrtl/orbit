<?php

declare(strict_types=1);

use App\Infrastructure\Metrics\MetricsConfigurationRenderer;
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

    it('runs both Metrics containers on host networking with no published ports', function (): void {
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
            metricsCommandResult(),
            metricsCommandResult(),
            metricsCommandResult(stdout: "running healthy\n"),
            metricsCommandResult(),
            metricsCommandResult(),
            metricsCommandResult(stdout: "running healthy\n"),
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

        expect($containerRuns)->toHaveCount(2);

        foreach ($containerRuns as $command) {
            expect($command)
                ->toContain("'--network' 'host'")
                ->not->toContain("'--publish'");
        }

        $prometheusRun = $containerRuns[0];
        $grafanaRun = $containerRuns[1];

        expect($prometheusRun)
            ->toContain("'--web.listen-address=127.0.0.1:9090'")
            ->and($grafanaRun)
            ->toContain("'GF_SERVER_HTTP_ADDR=10.44.0.3'");
    });

    it('bounds container logging with a rotating json-file driver', function (): void {
        $spec = new MetricsRuntimeSpec()->for(
            MetricsService::Prometheus,
            41,
            '10.44.0.3',
            'configuration',
        );
        $absent = [metricsCommandResult(exitCode: 1), metricsCommandResult()];
        $ssh = new MetricsCapturingSshExecutor([
            ...$absent,
            ...$absent,
            ...$absent,
            metricsCommandResult(),
            metricsCommandResult(),
            metricsCommandResult(stdout: "running healthy\n"),
        ]);

        metricsSshExecutor($ssh)->convergeContainers(metricsSshNode(), [$spec]);

        $commands = array_map(
            static fn (RemoteCommand $command): string => $command->shellCommand(),
            $ssh->commands,
        );
        $containerRun = array_values(array_filter(
            $commands,
            static fn (string $command): bool => str_contains($command, "'docker' 'container' 'run'"),
        ))[0];

        expect($containerRun)
            ->toContain("'--log-driver' 'json-file'")
            ->toContain("'--log-opt' 'max-size=10m'")
            ->toContain("'--log-opt' 'max-file=3'");
    });

    it('removes a proven recovery container', function (): void {
        $spec = new MetricsRuntimeSpec()->for(
            MetricsService::Prometheus,
            41,
            '10.44.0.3',
            'configuration',
        );
        $ssh = new MetricsCapturingSshExecutor([
            metricsCommandResult(exitCode: 1),
            metricsCommandResult(),
            metricsCommandResult(stdout: json_encode($spec->labels, JSON_THROW_ON_ERROR)."\n"),
            metricsCommandResult(),
        ]);

        metricsSshExecutor($ssh)->removeContainers(metricsSshNode(), [$spec]);

        $commands = array_map(
            static fn (RemoteCommand $command): string => $command->shellCommand(),
            $ssh->commands,
        );
        expect($commands)
            ->toContain("'docker' 'container' 'rm' '--force' '--' 'orbit-metrics-prometheus-orbit-rollback'");
    });

    it('validates the generated Prometheus configuration through the pinned promtool entrypoint', function (): void {
        $ssh = new MetricsCapturingSshExecutor([]);
        $executor = metricsSshExecutor($ssh);
        $bundle = new MetricsConfigurationRenderer()->render(
            [['name' => 'metrics-runtime', 'address' => '10.44.0.3']],
            'admin-password-sentinel',
        );

        $executor->publishConfiguration(metricsSshNode(), $bundle);

        expect($ssh->commands[0]->shellCommand())
            ->toBe(
                "'docker' 'container' 'run' '--rm' '--interactive' '--entrypoint' '/bin/promtool' "
                ."'prom/prometheus:v3.5.0' 'check' 'config' '/dev/stdin'",
            );
    });

    it('stages a generated file as root-only then chowns it for the container identity', function (): void {
        $ssh = new MetricsCapturingSshExecutor([]);
        $executor = metricsSshExecutor($ssh);
        $bundle = new MetricsConfigurationRenderer()->render(
            [['name' => 'metrics-runtime', 'address' => '10.44.0.3']],
            'admin-password-sentinel',
        );

        $executor->publishConfiguration(metricsSshNode(), $bundle);

        $commands = array_map(
            static fn (RemoteCommand $command): string => $command->shellCommand(),
            $ssh->commands,
        );

        expect($commands)
            ->toContain(
                "'sudo' 'install' '-m' '0400' '/dev/stdin' '/etc/orbit/metrics/grafana/admin-password.orbit-candidate'",
            )
            ->toContain(
                "'sudo' 'chown' '--' '472:472' '/etc/orbit/metrics/grafana/admin-password.orbit-candidate'",
            );
    });

    it('never writes standard input straight onto a live configuration path', function (): void {
        $ssh = new MetricsCapturingSshExecutor([]);
        $executor = metricsSshExecutor($ssh);
        $bundle = new MetricsConfigurationRenderer()->render(
            [['name' => 'metrics-runtime', 'address' => '10.44.0.3']],
            'admin-password-sentinel',
        );

        $executor->publishConfiguration(metricsSshNode(), $bundle);

        $installed = [];
        $moved = [];

        foreach ($ssh->commands as $command) {
            $arguments = $command->arguments;

            if (in_array('/dev/stdin', $arguments, true) && in_array('install', $arguments, true)) {
                $installed[] = $arguments[array_key_last($arguments)];
            }

            if (array_slice($arguments, 0, 4) === ['sudo', 'mv', '-fT', '--']) {
                $moved[] = $arguments[count($arguments) - 2];
            }
        }

        expect($installed)
            ->toContain('/etc/orbit/metrics/.orbit-owner.orbit-candidate')
            ->each
            ->toEndWith('.orbit-candidate')
            ->and($moved)
            ->toBe($installed);
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
        healthAttempts: 3,
        healthPollMicroseconds: 0,
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

describe('credential file snapshots', function (): void {
    it('snapshots a lagging Grafana credential file as protected content instead of refusing', function (): void {
        $renderer = new App\Infrastructure\Metrics\MetricsConfigurationRenderer;
        $bundle = $renderer->render([['name' => 'app-dev', 'address' => '10.44.0.3']], 'rotated-credential-sentinel');
        $results = [metricsCommandResult(), metricsCommandResult(stdout: "metrics\n")];

        foreach ($bundle->files as $file) {
            $results[] = metricsCommandResult();
            $results[] = metricsCommandResult(
                stdout: str_ends_with($file->path, 'admin-password')
                    ? 'stale-credential-sentinel'
                    : 'public',
            );
        }

        $ssh = new MetricsCapturingSshExecutor($results);

        $snapshot = metricsSshExecutor($ssh)->snapshotConfiguration(metricsSshNode(), $bundle);

        $credential = $snapshot->files['/etc/orbit/metrics/grafana/admin-password'];
        expect($snapshot->directoryExisted)
            ->toBeTrue()
            ->and($credential?->contents->sha256())
            ->toBe(hash('sha256', 'stale-credential-sentinel'))
            ->and($credential?->mode)
            ->toBe(0o400)
            ->and(print_r($snapshot, true))
            ->not->toContain('stale-credential-sentinel')->and(array_map(
                static fn (RemoteCommand $command): array => $command->arguments,
                $ssh->commands,
            ))
            ->not->toContain(['sudo', 'sha256sum', '--', '/etc/orbit/metrics/grafana/admin-password']);
    });
});

describe('credential verification', function (): void {
    it('verifies a Grafana password against an authenticated endpoint', function (): void {
        $ssh = new MetricsCapturingSshExecutor([]);
        $executor = metricsSshExecutor($ssh);

        $executor->verify(metricsSshNode(), 'password-sentinel');

        $configuration = stream_get_contents($ssh->commands[0]->protectedInput?->stream());

        expect($ssh->commands[0]->arguments)
            ->toBe(['curl', '--config', '-'])
            ->and($configuration)
            ->toContain('url = "http://10.44.0.3:3000/api/user"')
            ->toContain('fail')
            ->toContain('user = "admin:password-sentinel"')
            // /api/health answers without credentials, so probing it proves the
            // service is up and nothing about the password.
            ->not->toContain('/api/health');
    });

    it('reports an unverified password when Grafana refuses the request', function (): void {
        $ssh = new MetricsCapturingSshExecutor([metricsCommandResult(exitCode: 22)]);
        $executor = metricsSshExecutor($ssh);

        expect($executor->verify(metricsSshNode(), 'stale-password'))->toBeFalse();
    });
});
