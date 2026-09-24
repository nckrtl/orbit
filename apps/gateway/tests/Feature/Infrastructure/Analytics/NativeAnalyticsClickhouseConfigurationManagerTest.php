<?php

declare(strict_types=1);

use App\Domain\Analytics\AnalyticsClickhouseConfigurationManager;
use App\Domain\Nodes\NodeRoleOperationException;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Process;
use Tests\Support\ProcessesApiFakeRuntimeManager;

/** A Node filesystem that answers the fixed commands the configuration manager sends. */
final class ClickhouseConfigurationSsh implements SshExecutor
{
    /** @var list<RemoteCommand> */
    public array $commands = [];

    /** @var list<string> */
    public array $hosts = [];

    /** @var array<string, string> */
    public array $files = [];

    /** @var (Closure(list<string>): bool)|null */
    public ?Closure $fails = null;

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->commands[] = $command;
        $this->hosts[] = $connection->host;
        $arguments = $command->arguments;
        $path = $arguments[array_key_last($arguments)];

        if ($this->fails instanceof Closure && ($this->fails)($arguments)) {
            return new CommandResult(1, '', 'failed', 1, false);
        }

        if ($arguments[1] === 'test') {
            return new CommandResult(array_key_exists($path, $this->files) ? 0 : 1, '', '', 1, false);
        }

        if ($arguments[1] === 'cat') {
            return new CommandResult(0, $this->files[$path] ?? '', '', 1, false);
        }

        if ($arguments[1] === 'install' && $command->input !== null) {
            $this->files[$path] = $command->input;
        }

        if ($arguments[1] === 'mv') {
            $source = $arguments[count($arguments) - 2];
            $this->files[$path] = $this->files[$source];
            unset($this->files[$source]);
        }

        return new CommandResult(0, '', '', 1, false);
    }

    /** @return list<list<string>> */
    public function writes(): array
    {
        return array_values(array_map(
            static fn (RemoteCommand $command): array => $command->arguments,
            array_filter($this->commands, static fn (RemoteCommand $command): bool => in_array($command->arguments[1], ['install', 'mv'], true)
                && ! in_array('-d', $command->arguments, true)),
        ));
    }
}

/**
 * Plausible Community Edition's `clickhouse/` files at commit ec6c4da77654.
 *
 * @return array<string, string>
 */
function plausible_clickhouse_sha256(): array
{
    return [
        '/etc/orbit/analytics/clickhouse/config.d/logs.xml' => '8f1e01eee64c62fbe6565d3f0a2d2cd1c2755b2f18ebc64f44af6bf47a4a1c56',
        '/etc/orbit/analytics/clickhouse/config.d/ipv4-only.xml' => '8fba0c4d96c8cef933b33ec80017e3024f19e1534a4cc5a2f91a69a81ae8fc12',
        '/etc/orbit/analytics/clickhouse/config.d/low-resources.xml' => '7c4e4105a0d76661a97bdcbe768d199b96706a88812ef5e37bb95ed8626c435c',
        '/etc/orbit/analytics/clickhouse/users.d/default-profile-low-resources-overrides.xml' => '5d4b3297f73a757ee273bf9ea45cefd039baf2b3f29e6bf6bd7520002f9286d4',
    ];
}

/** @return list<array{source: string, target: string, read_only: true}> */
function plausible_clickhouse_mounts(): array
{
    return [
        ['source' => '/etc/orbit/analytics/clickhouse/config.d/logs.xml', 'target' => '/etc/clickhouse-server/config.d/logs.xml', 'read_only' => true],
        ['source' => '/etc/orbit/analytics/clickhouse/config.d/ipv4-only.xml', 'target' => '/etc/clickhouse-server/config.d/ipv4-only.xml', 'read_only' => true],
        ['source' => '/etc/orbit/analytics/clickhouse/config.d/low-resources.xml', 'target' => '/etc/clickhouse-server/config.d/low-resources.xml', 'read_only' => true],
        ['source' => '/etc/orbit/analytics/clickhouse/users.d/default-profile-low-resources-overrides.xml', 'target' => '/etc/clickhouse-server/users.d/default-profile-low-resources-overrides.xml', 'read_only' => true],
    ];
}

/** The production ClickHouse Process before the role applied Plausible's configuration. */
function clickhouse_configuration_process(): Process
{
    $clickhouse = analytics_storage_processes()['clickhouse'];
    $clickhouse->update(['runtime_config' => [
        ...$clickhouse->runtime_config,
        'command' => ['--', '--logger.level=warning'],
        'volumes' => [['source' => 'analytics-clickhouse', 'target' => '/var/lib/clickhouse', 'read_only' => false]],
    ]]);

    return $clickhouse->refresh();
}

beforeEach(function (): void {
    $this->ssh = new ClickhouseConfigurationSsh;
    $this->runtime = new ProcessesApiFakeRuntimeManager;
    app()->instance(SshExecutor::class, $this->ssh);
    app()->instance(ProcessRuntimeManager::class, $this->runtime);
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('privateKeyPath')->andReturn('/test/id');
    app()->instance(SshKeyProvider::class, $keys);
    $hosts = Mockery::mock(KnownHostsStore::class);
    $hosts->shouldReceive('path')->andReturn('/test/known-hosts');
    app()->instance(KnownHostsStore::class, $hosts);
    $this->manager = app(AnalyticsClickhouseConfigurationManager::class);
});

describe('applying Plausible\'s ClickHouse configuration', function (): void {
    it('publishes Plausible\'s four files unchanged as root-owned 0644 files on the ClickHouse Node', function (): void {
        $clickhouse = clickhouse_configuration_process();

        $this->manager->converge($clickhouse);

        expect(array_map(static fn (string $contents): string => hash('sha256', $contents), $this->ssh->files))
            ->toEqual(plausible_clickhouse_sha256())
            ->and(array_unique($this->ssh->hosts))->toBe(['10.44.0.200'])
            ->and($this->ssh->commands[0]->arguments)->toBe([
                'sudo', 'install', '-d', '-o', 'root', '-g', 'root', '-m', '0755', '--',
                '/etc/orbit/analytics/clickhouse/config.d', '/etc/orbit/analytics/clickhouse/users.d',
            ])
            ->and($this->ssh->writes())->toContain(
                ['sudo', 'install', '-o', 'root', '-g', 'root', '-m', '0644', '/dev/stdin', '/etc/orbit/analytics/clickhouse/config.d/logs.xml.orbit-candidate'],
                ['sudo', 'mv', '-fT', '--', '/etc/orbit/analytics/clickhouse/config.d/logs.xml.orbit-candidate', '/etc/orbit/analytics/clickhouse/config.d/logs.xml'],
            );
    });

    it('adds the missing read-only mounts, keeps the rest of the Process, and replaces its container', function (): void {
        $clickhouse = clickhouse_configuration_process();
        $before = $clickhouse->runtime_config;

        $this->manager->converge($clickhouse);

        $after = $clickhouse->refresh();

        expect($after->runtime_config)->toEqual([
            ...$before,
            'volumes' => [$before['volumes'][0], ...plausible_clickhouse_mounts()],
        ])
            ->and($after->name)->toBe('plausible-clickhouse')
            ->and($after->status)->toBe(LifecycleStatus::Active)
            ->and($this->runtime->convergedProcessIds)->toBe([$clickhouse->id])
            ->and($this->runtime->restarted)->toBe([]);
    });

    it('adds only the mounts a Process lacks', function (): void {
        $clickhouse = clickhouse_configuration_process();
        $clickhouse->update(['runtime_config' => [
            ...$clickhouse->runtime_config,
            'volumes' => [...$clickhouse->runtime_config['volumes'], plausible_clickhouse_mounts()[1]],
        ]]);

        $this->manager->converge($clickhouse);

        expect($clickhouse->refresh()->runtime_config['volumes'])->toEqual([
            ['source' => 'analytics-clickhouse', 'target' => '/var/lib/clickhouse', 'read_only' => false],
            plausible_clickhouse_mounts()[1],
            plausible_clickhouse_mounts()[0],
            plausible_clickhouse_mounts()[2],
            plausible_clickhouse_mounts()[3],
        ]);
    });

    it('writes nothing and leaves ClickHouse running when nothing changed', function (): void {
        $clickhouse = clickhouse_configuration_process();
        $this->manager->converge($clickhouse);
        $this->ssh->commands = [];
        $this->runtime->convergedProcessIds = [];
        $configuration = $clickhouse->refresh()->runtime_config;

        $this->manager->converge($clickhouse);

        expect($this->ssh->writes())->toBe([])
            ->and($clickhouse->refresh()->runtime_config)->toEqual($configuration)
            ->and($this->runtime->convergedProcessIds)->toBe([])
            ->and($this->runtime->restarted)->toBe([]);
    });

    it('restarts ClickHouse when only a file changed', function (): void {
        $clickhouse = clickhouse_configuration_process();
        $this->manager->converge($clickhouse);
        $this->ssh->files['/etc/orbit/analytics/clickhouse/config.d/logs.xml'] = "<clickhouse />\n";
        $this->ssh->commands = [];
        $configuration = $clickhouse->refresh()->runtime_config;

        $this->manager->converge($clickhouse);

        expect(hash('sha256', $this->ssh->files['/etc/orbit/analytics/clickhouse/config.d/logs.xml']))
            ->toBe(plausible_clickhouse_sha256()['/etc/orbit/analytics/clickhouse/config.d/logs.xml'])
            ->and($this->ssh->writes())->toHaveCount(2)
            ->and($clickhouse->refresh()->runtime_config)->toEqual($configuration)
            ->and($this->runtime->restarted)->toBe([$clickhouse->id]);
    });

    it('does not start a ClickHouse Process the operator stopped', function (): void {
        $clickhouse = clickhouse_configuration_process();
        $this->manager->converge($clickhouse);
        $clickhouse->update(['desired_state' => DesiredProcessState::Stopped]);
        unset($this->ssh->files['/etc/orbit/analytics/clickhouse/config.d/low-resources.xml']);

        $this->manager->converge($clickhouse);

        expect($this->ssh->files)->toHaveKey('/etc/orbit/analytics/clickhouse/config.d/low-resources.xml')
            ->and($this->runtime->restarted)->toBe([]);
    });

    it('refuses an operator volume on one of the container paths before it changes the Node', function (): void {
        $clickhouse = clickhouse_configuration_process();
        $clickhouse->update(['runtime_config' => [
            ...$clickhouse->runtime_config,
            'volumes' => [['source' => '/srv/logs.xml', 'target' => '/etc/clickhouse-server/config.d/logs.xml', 'read_only' => true]],
        ]]);

        expect(fn () => $this->manager->converge($clickhouse))->toThrow(
            fn (NodeRoleOperationException $exception) => expect($exception->step)->toBe('clickhouse-config')
                ->and($exception->underlyingErrorCode)->toBe('analytics.clickhouse_mount_conflict'),
        );
        expect($this->ssh->commands)->toBe([])
            ->and($this->runtime->convergedProcessIds)->toBe([]);
    });

    it('reports a failed file publication at the clickhouse-config step', function (): void {
        $clickhouse = clickhouse_configuration_process();
        $this->ssh->fails = static fn (array $arguments): bool => $arguments[1] === 'mv';

        expect(fn () => $this->manager->converge($clickhouse))->toThrow(
            fn (NodeRoleOperationException $exception) => expect($exception->step)->toBe('clickhouse-config')
                ->and($exception->errorCode)->toBe('node_role.convergence_failed')
                ->and($exception->underlyingErrorCode)->toBe('analytics.clickhouse_config_failed'),
        );
        expect($this->runtime->convergedProcessIds)->toBe([])
            ->and($clickhouse->refresh()->runtime_config['volumes'])->toHaveCount(1);
    });

    it('reports a failed container replacement at the clickhouse-config step, and retries it on the next converge', function (): void {
        $clickhouse = clickhouse_configuration_process();
        $this->runtime->failConverge = true;

        expect(fn () => $this->manager->converge($clickhouse))->toThrow(
            fn (NodeRoleOperationException $exception) => expect($exception->step)->toBe('clickhouse-config')
                ->and($exception->underlyingErrorCode)->toBe('process.docker_converge_failed'),
        );
        expect($clickhouse->refresh())
            ->status->toBe(LifecycleStatus::Failed)
            ->failed_step->toBe('create-container');

        $this->runtime->failConverge = false;
        $this->manager->converge($clickhouse);

        expect($this->runtime->convergedProcessIds)->toBe([$clickhouse->id, $clickhouse->id])
            ->and($clickhouse->refresh()->status)->toBe(LifecycleStatus::Active);
    });
});
