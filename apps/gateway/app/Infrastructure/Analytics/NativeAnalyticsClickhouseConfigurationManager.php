<?php

declare(strict_types=1);

namespace App\Infrastructure\Analytics;

use App\Domain\Analytics\AnalyticsClickhouseConfigurationManager;
use App\Domain\Analytics\PlausibleClickhouseConfiguration;
use App\Domain\Nodes\NodeRoleOperationException;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessOperationException;
use App\Domain\Processes\ProcessRuntimeLease;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use App\Models\Process;
use SensitiveParameter;

/**
 * Publishes Plausible's ClickHouse files on the ClickHouse Process's Node, adds their read-only
 * mounts to the Process, and reloads ClickHouse through the Process runtime only when either
 * changed. A new mount changes the Process specification, so the runtime replaces the container,
 * which reads the files as it starts; changed files alone need a restart.
 */
final readonly class NativeAnalyticsClickhouseConfigurationManager implements AnalyticsClickhouseConfigurationManager
{
    public const string STEP = 'clickhouse-config';

    private const string CANDIDATE_SUFFIX = '.orbit-candidate';

    public function __construct(
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
        private ProcessRuntimeManager $runtime,
        private ProcessRuntimeLease $lease,
    ) {}

    public function converge(#[SensitiveParameter] Process $clickhouse): void
    {
        // Refuse a conflicting operator mount before anything on the Node changes.
        $this->missingMounts($clickhouse);

        $filesChanged = $this->publishFiles($this->node($clickhouse));

        $this->lease->run($clickhouse, function (Process $fresh) use ($filesChanged): void {
            $missing = $this->missingMounts($fresh);
            // A Process a previous converge left failed may still run the old files or specification.
            $recovering = $fresh->status === LifecycleStatus::Failed;

            if ($missing === [] && ! $filesChanged && ! $recovering) {
                return;
            }

            if ($missing !== []) {
                $volumes = $fresh->runtime_config['volumes'] ?? [];
                $fresh->update(['runtime_config' => [
                    ...$fresh->runtime_config,
                    'volumes' => [...(is_array($volumes) ? $volumes : []), ...$missing],
                ]]);
            }

            try {
                $this->runtime->converge($fresh);

                if ($missing === [] && $fresh->desired_state === DesiredProcessState::Running) {
                    $this->runtime->restart($fresh);
                }
            } catch (ProcessOperationException|ResourceOperationException $exception) {
                $fresh->update([
                    'status' => LifecycleStatus::Failed,
                    'failed_step' => $exception instanceof ProcessOperationException ? $exception->step : self::STEP,
                    'error_code' => $exception->errorCode,
                ]);

                throw new NodeRoleOperationException(
                    self::STEP,
                    'node_role.convergence_failed',
                    $exception->errorCode,
                    "ClickHouse Process [{$fresh->name}] did not restart with Plausible's configuration.",
                    $exception instanceof ProcessOperationException ? $exception->result : null,
                    $exception,
                );
            }

            $fresh->update(['status' => LifecycleStatus::Active, 'failed_step' => null, 'error_code' => null]);
        });
    }

    /** @return list<array{source: string, target: string, read_only: true}> */
    private function missingMounts(#[SensitiveParameter] Process $clickhouse): array
    {
        try {
            return PlausibleClickhouseConfiguration::missingMounts(
                $clickhouse->runtime_config['volumes'] ?? [],
                $clickhouse->name,
            );
        } catch (ResourceOperationException $exception) {
            throw new NodeRoleOperationException(
                self::STEP,
                'node_role.convergence_failed',
                $exception->errorCode,
                $exception->getMessage(),
                previous: $exception,
            );
        }
    }

    /** Writes each file that differs through a candidate beside it, so ClickHouse never reads a partial file. */
    private function publishFiles(Node $node): bool
    {
        $this->run($node, new RemoteCommand([
            'sudo', 'install', '-d', '-o', 'root', '-g', 'root', '-m', '0755', '--',
            ...PlausibleClickhouseConfiguration::hostDirectories(),
        ]));
        $changed = false;

        foreach (array_keys(PlausibleClickhouseConfiguration::FILES) as $file) {
            $path = PlausibleClickhouseConfiguration::hostPath($file);
            $contents = PlausibleClickhouseConfiguration::contents($file);

            if ($this->readFile($node, $path) === $contents) {
                continue;
            }

            $candidate = $path.self::CANDIDATE_SUFFIX;
            $this->run($node, new RemoteCommand(['sudo', 'rm', '-f', '--', $candidate]));
            $this->run($node, new RemoteCommand(
                ['sudo', 'install', '-o', 'root', '-g', 'root', '-m', '0644', '/dev/stdin', $candidate],
                input: $contents,
            ));
            $this->run($node, new RemoteCommand(['sudo', 'mv', '-fT', '--', $candidate, $path]));
            $changed = true;
        }

        return $changed;
    }

    private function readFile(Node $node, string $path): ?string
    {
        $exists = $this->execute($node, new RemoteCommand(['sudo', 'test', '-f', $path]));

        if ($exists->exitCode === 1) {
            return null;
        }

        if (! $exists->succeeded()) {
            $this->fail($node, $exists);
        }

        return $this->run($node, new RemoteCommand(['sudo', 'cat', '--', $path]))->stdout;
    }

    private function run(Node $node, RemoteCommand $command): CommandResult
    {
        $result = $this->execute($node, $command);

        if (! $result->succeeded()) {
            $this->fail($node, $result);
        }

        return $result;
    }

    private function fail(Node $node, ?CommandResult $result = null): never
    {
        throw new NodeRoleOperationException(
            self::STEP,
            'node_role.convergence_failed',
            'analytics.clickhouse_config_failed',
            "Plausible's ClickHouse configuration could not be published on node [{$node->name}].",
            $result,
        );
    }

    private function execute(Node $node, RemoteCommand $command): CommandResult
    {
        if (! is_string($node->wireguard_ip) || $node->wireguard_ip === '') {
            $this->fail($node);
        }

        return $this->ssh->execute(
            new SshConnection(
                host: $node->wireguard_ip,
                user: $node->user,
                port: 22,
                identityFile: $this->keys->privateKeyPath(),
                knownHostsFile: $this->knownHosts->path(),
            ),
            $command,
        );
    }

    private function node(#[SensitiveParameter] Process $clickhouse): Node
    {
        $clickhouse->loadMissing('owner');
        $node = $clickhouse->owner;

        if (! $node instanceof Node) {
            throw new NodeRoleOperationException(
                self::STEP,
                'node_role.convergence_failed',
                'analytics.process_not_node',
                "Process [{$clickhouse->name}] is not a Node-targeted Process.",
            );
        }

        return $node;
    }
}
