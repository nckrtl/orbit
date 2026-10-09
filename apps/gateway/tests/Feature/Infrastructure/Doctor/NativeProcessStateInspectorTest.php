<?php

declare(strict_types=1);

use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\ProcessInspectionStatus;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Doctor\NativeProcessStateInspector;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\DockerProcessRenderer;
use App\Infrastructure\Processes\SystemdProcessRenderer;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use App\Models\Project;
use Illuminate\Support\Sleep;

it('inspects an owned systemd unit with exact read-only commands on the selected node', function (): void {
    $deadline = new CommandDeadline(static fn (): float => 100.0);
    $deadline->start(12.5);
    [$inspector, $ssh, $process] = native_process_inspector(
        ProcessRuntime::Systemd,
        [
            native_process_result(),
            native_process_result(),
            native_process_result("ActiveState=active\nSubState=running\nNRestarts=0\n"),
            native_process_result("ActiveState=active\nSubState=running\nNRestarts=0\n"),
        ],
        $deadline,
    );
    $unit = new SystemdProcessRenderer()->unitName($process);
    $path = new SystemdProcessRenderer()->unitPath($process);

    $state = $inspector->inspect($process);

    expect($state->present)
        ->toBeTrue()
        ->and($state->status)
        ->toBe(ProcessInspectionStatus::Active)
        ->and(array_map(
            static fn (array $call): array => $call['command']->arguments,
            $ssh->calls,
        ))
        ->toBe([
            ['sudo', 'test', '-e', $path],
            ['sudo', 'grep', '-Fqx', '--', "X-Orbit-Process-ID={$process->id}", $path],
            ['sudo', 'systemctl', 'show', '--property=ActiveState,SubState,NRestarts', '--', $unit],
            ['sudo', 'systemctl', 'show', '--property=ActiveState,SubState,NRestarts', '--', $unit],
        ])
        ->and(array_column($ssh->calls, 'connection'))
        ->each(fn ($connection) => $connection->toEqual(
            new SshConnection('10.44.0.51', 'nckrtl', 22, '/managed-key', '/pinned-hosts', commandTimeout: 10.0),
        ));
    Sleep::assertSequence([Sleep::for(2)->seconds()]);
});

it('inspects systemd Processes with the API cleanup reserve without exhausting the local budget', function (
    array $samples,
    bool $crashLoop,
    array $timeouts,
    float $remaining,
): void {
    $now = 100.0;
    $deadline = new CommandDeadline(static function () use (&$now): float {
        return $now;
    });
    $deadline->start(570.0, CommandDeadline::CleanupReserveSeconds);
    [$inspector, $ssh, $process] = native_process_inspector(ProcessRuntime::Systemd, [
        native_process_result(),
        native_process_result(),
        ...array_map(native_process_result(...), $samples),
    ], $deadline);
    Sleep::whenFakingSleep(static function () use (&$now): void {
        $now += 2.0;
    });

    $state = $inspector->inspect($process);

    expect($state->present)->toBeTrue()
        ->and($state->isCrashLoop())->toBe($crashLoop)
        ->and(array_map(
            static fn (array $call): float => $call['connection']->commandTimeout,
            $ssh->calls,
        ))->toBe($timeouts)
        ->and($deadline->cap(9999.0))->toBe($remaining);
    Sleep::assertSleptTimes(count($samples) - 1);

    $now = 649.0;
    expect($deadline->cap(30.0))->toBe(1.0);
    $now = 650.0;
    expect(fn () => $deadline->cap(30.0))->toThrow(ResourceOperationException::class);
    expect($deadline->cap(30.0))->toBe(20.0);
})->with([
    'healthy after earlier restarts' => [
        ["ActiveState=active\nSubState=running\nNRestarts=26000\n", "ActiveState=active\nSubState=running\nNRestarts=26000\n"],
        false, [10.0, 10.0, 10.0, 8.0], 548.0,
    ],
    'crash loop with active samples' => [
        ["ActiveState=active\nSubState=running\nNRestarts=3\n", "ActiveState=active\nSubState=running\nNRestarts=4\n"],
        true, [10.0, 10.0, 10.0, 8.0], 548.0,
    ],
    'crash loop in auto-restart' => [
        ["ActiveState=activating\nSubState=auto-restart\nNRestarts=3\n"],
        true, [10.0, 10.0, 10.0], 550.0,
    ],
]);

it('continues inspection after a caught local expiry without releasing the parent cleanup reserve', function (): void {
    $now = 100.0;
    $deadline = new CommandDeadline(static function () use (&$now): float {
        return $now;
    });
    $deadline->start(570.0, CommandDeadline::CleanupReserveSeconds);
    [$inspector, $ssh, $process] = native_process_inspector(ProcessRuntime::Systemd, [
        native_process_result(),
        native_process_result(),
        native_process_result("ActiveState=active\nSubState=running\nNRestarts=26000\n"),
        native_process_result(),
        native_process_result(),
        native_process_result("ActiveState=active\nSubState=running\nNRestarts=26000\n"),
        native_process_result("ActiveState=active\nSubState=running\nNRestarts=26000\n"),
    ], $deadline);
    $waits = 0;
    Sleep::whenFakingSleep(static function () use (&$now, &$waits): void {
        $now += ++$waits === 1 ? 10.0 : 2.0;
    });

    expect(fn () => $inspector->inspect($process))->toThrow(DoctorInspectionException::class);
    expect($deadline->cap(9999.0))->toBe(540.0);

    $state = $inspector->inspect($process);

    expect($state->status)->toBe(ProcessInspectionStatus::Active)
        ->and($state->isCrashLoop())->toBeFalse()
        ->and(array_map(
            static fn (array $call): float => $call['connection']->commandTimeout,
            $ssh->calls,
        ))->toBe([10.0, 10.0, 10.0, 10.0, 10.0, 10.0, 8.0])
        ->and($deadline->cap(9999.0))->toBe(538.0);
    Sleep::assertSleptTimes(2);

    $now = 649.0;
    expect($deadline->cap(30.0))->toBe(1.0);
    $now = 650.0;
    expect(fn () => $inspector->inspect($process))->toThrow(DoctorInspectionException::class);
    expect($ssh->calls)->toHaveCount(7)->and($deadline->cap(30.0))->toBe(20.0);
    expect(fn () => $inspector->inspect($process))->toThrow(DoctorInspectionException::class);
    expect($ssh->calls)->toHaveCount(7)->and($deadline->cap(30.0))->toBe(20.0);
});

it('returns bounded restart samples without treating historical or reset counts as a crash loop', function (string $before, string $after): void {
    [$inspector, $ssh, $process] = native_process_inspector(ProcessRuntime::Systemd, [
        native_process_result(),
        native_process_result(),
        native_process_result($before),
        native_process_result($after),
    ]);

    $state = $inspector->inspect($process);

    expect($state->status)->toBe(ProcessInspectionStatus::Active)
        ->and($state->isCrashLoop())->toBeFalse()
        ->and($state->systemdObservations)->toHaveCount(2);
})->with([
    'counter was reset' => [
        "ActiveState=active\nSubState=running\nNRestarts=26000\n",
        "ActiveState=active\nSubState=running\nNRestarts=0\n",
    ],
    'unavailable count and unordered properties' => [
        "SubState=running\nActiveState=active\n",
        "NRestarts=\nSubState=running\nActiveState=active\n",
    ],
]);

it('reports an auto-restart observation without waiting for a second sample', function (): void {
    [$inspector, $ssh, $process] = native_process_inspector(ProcessRuntime::Systemd, [
        native_process_result(),
        native_process_result(),
        native_process_result("ActiveState=activating\nSubState=auto-restart\nNRestarts=3\n"),
    ]);

    $state = $inspector->inspect($process);

    expect($state->isCrashLoop())->toBeTrue()->and($ssh->calls)->toHaveCount(3);
    Sleep::assertNeverSlept();
});

it('does not wait beyond the remaining inspection deadline', function (): void {
    $deadline = new CommandDeadline(static fn (): float => 100.0);
    $deadline->start(1.0);
    [$inspector, $ssh, $process] = native_process_inspector(ProcessRuntime::Systemd, [
        native_process_result(),
        native_process_result(),
        native_process_result("ActiveState=active\nSubState=running\nNRestarts=0\n"),
    ], $deadline);

    expect(fn () => $inspector->inspect($process))->toThrow(DoctorInspectionException::class, '');

    Sleep::assertNeverSlept();
    expect($ssh->calls)->toHaveCount(3);
});

it('caps the second observation to the deadline remaining after the wait', function (): void {
    $now = 100.0;
    $deadline = new CommandDeadline(static function () use (&$now): float {
        return $now;
    });
    [$inspector, $ssh, $process] = native_process_inspector(ProcessRuntime::Systemd, [
        native_process_result(),
        native_process_result(),
        native_process_result("ActiveState=active\nSubState=running\nNRestarts=0\n"),
        native_process_result("ActiveState=active\nSubState=running\nNRestarts=0\n"),
    ], $deadline);
    Sleep::whenFakingSleep(static function () use (&$now): void {
        $now += 2.0;
    });

    $inspector->inspect($process);

    expect($ssh->calls[3]['connection']->commandTimeout)->toBe(8.0);
});

it('returns absent only for a known missing systemd unit', function (): void {
    [$inspector, $ssh, $process] = native_process_inspector(ProcessRuntime::Systemd, [
        new CommandResult(1, '', '', 1, false),
    ]);

    $state = $inspector->inspect($process);

    expect($state->present)
        ->toBeFalse()
        ->and($state->status)
        ->toBeNull()
        ->and($ssh->calls)
        ->toHaveCount(1);
});

it('inspects an owned Docker container with one exact bounded command', function (): void {
    [$inspector, $ssh, $process] = native_process_inspector(ProcessRuntime::Docker, []);
    $name = new DockerProcessRenderer()->containerName($process);
    $ssh->results = [native_process_result("true\nprocess\n{$process->id}\nrunning\n")];

    $state = $inspector->inspect($process);

    expect($state->present)
        ->toBeTrue()
        ->and($state->status)
        ->toBe(ProcessInspectionStatus::Running)
        ->and($ssh->calls)
        ->toHaveCount(1)
        ->and($ssh->calls[0]['command']->arguments)
        ->toBe([
            'sudo',
            'docker',
            'container',
            'inspect',
            '--format',
            '{{ index .Config.Labels "orbit.managed" }}{{ printf "\\n" }}{{ index .Config.Labels "orbit.container.kind" }}{{ printf "\\n" }}{{ index .Config.Labels "orbit.process.id" }}{{ printf "\\n" }}{{ .State.Status }}',
            $name,
        ])
        ->and($ssh->calls[0]['connection'])
        ->toEqual(new SshConnection(
            '10.44.0.51',
            'nckrtl',
            22,
            '/managed-key',
            '/pinned-hosts',
            commandTimeout: 30.0,
        ));
});

it('returns absent for a known missing Docker container', function (): void {
    [$inspector, $ssh, $process] = native_process_inspector(ProcessRuntime::Docker, [
        new CommandResult(1, '', 'Error: No such object: orbit-process', 1, false),
    ]);

    $state = $inspector->inspect($process);

    expect($state->present)
        ->toBeFalse()
        ->and($state->status)
        ->toBeNull()
        ->and($ssh->calls)
        ->toHaveCount(1);
});

it('maps native runtime states to a bounded inspection status', function (
    ProcessRuntime $runtime,
    array $results,
    ProcessInspectionStatus $expected,
): void {
    [$inspector, $ssh, $process] = native_process_inspector($runtime, []);
    $ssh->results = array_map(
        static fn (array $result): CommandResult => new CommandResult(...$result),
        array_map(
            static fn (array $result): array => [
                $result[0],
                str_replace('{id}', (string) $process->id, $result[1]),
                $result[2],
                1,
                false,
            ],
            $results,
        ),
    );

    expect($inspector->inspect($process)->status)->toBe($expected);
})->with([
    'inactive systemd' => [
        ProcessRuntime::Systemd,
        [[0, '', ''], [0, '', ''], [0, "ActiveState=inactive\nSubState=dead\nNRestarts=0\n", ''], [0, "ActiveState=inactive\nSubState=dead\nNRestarts=0\n", '']],
        ProcessInspectionStatus::Inactive,
    ],
    'failed systemd' => [
        ProcessRuntime::Systemd,
        [[0, '', ''], [0, '', ''], [0, "ActiveState=failed\nSubState=failed\nNRestarts=0\n", ''], [0, "ActiveState=failed\nSubState=failed\nNRestarts=0\n", '']],
        ProcessInspectionStatus::Other,
    ],
    'created Docker' => [
        ProcessRuntime::Docker,
        [[0, "true\nprocess\n{id}\ncreated\n", '']],
        ProcessInspectionStatus::Created,
    ],
    'exited Docker' => [
        ProcessRuntime::Docker,
        [[0, "true\nprocess\n{id}\nexited\n", '']],
        ProcessInspectionStatus::Exited,
    ],
    'paused Docker' => [
        ProcessRuntime::Docker,
        [[0, "true\nprocess\n{id}\npaused\n", '']],
        ProcessInspectionStatus::Other,
    ],
]);

it('fails closed without exception text for collisions malformed output failures truncation and transport errors', function (
    ProcessRuntime $runtime,
    array $results,
    bool $throws = false,
): void {
    [$inspector, $ssh, $process] = native_process_inspector($runtime, []);
    $ssh->results = array_map(
        static fn (array $result): CommandResult => new CommandResult(
            $result[0],
            str_replace('{id}', (string) $process->id, $result[1]),
            $result[2],
            1,
            $result[3] ?? false,
        ),
        $results,
    );
    $ssh->throws = $throws;

    expect(fn () => $inspector->inspect($process))->toThrow(DoctorInspectionException::class, '');
})->with([
    'systemd existence failure' => [ProcessRuntime::Systemd, [[2, 'secret-output', 'secret-error']]],
    'systemd ownership collision' => [ProcessRuntime::Systemd, [[0, '', ''], [1, '', '']]],
    'systemd malformed status' => [
        ProcessRuntime::Systemd,
        [
            [0, '',                 ''],
            [0, '',                 ''],
            [0, "active\nsecret\n", ''],
        ],
    ],
    'systemd truncated status' => [
        ProcessRuntime::Systemd,
        [[0, '', ''], [0, '', ''], [0, "ActiveState=active\nSubState=running\nNRestarts=0\n", '', true]],
    ],
    'systemd unknown active state' => [ProcessRuntime::Systemd, [[0, '', ''], [0, '', ''], [0, "ActiveState=secret\nSubState=running\nNRestarts=0\n", '']]],
    'systemd unknown sub-state' => [ProcessRuntime::Systemd, [[0, '', ''], [0, '', ''], [0, "ActiveState=active\nSubState=secret\nNRestarts=0\n", '']]],
    'systemd negative count' => [ProcessRuntime::Systemd, [[0, '', ''], [0, '', ''], [0, "ActiveState=active\nSubState=running\nNRestarts=-1\n", '']]],
    'systemd count overflow' => [ProcessRuntime::Systemd, [[0, '', ''], [0, '', ''], [0, "ActiveState=active\nSubState=running\nNRestarts=4294967296\n", '']]],
    'systemd nonnumeric count' => [ProcessRuntime::Systemd, [[0, '', ''], [0, '', ''], [0, "ActiveState=active\nSubState=running\nNRestarts=secret\n", '']]],
    'systemd duplicate property' => [ProcessRuntime::Systemd, [[0, '', ''], [0, '', ''], [0, "ActiveState=active\nSubState=running\nSubState=running\n", '']]],
    'systemd unexpected property' => [ProcessRuntime::Systemd, [[0, '', ''], [0, '', ''], [0, "ActiveState=active\nSubState=running\nSecret=secret\n", '']]],
    'systemd missing sub-state' => [ProcessRuntime::Systemd, [[0, '', ''], [0, '', ''], [0, "ActiveState=active\nNRestarts=0\n", '']]],
    'systemd unterminated output' => [ProcessRuntime::Systemd, [[0, '', ''], [0, '', ''], [0, "ActiveState=active\nSubState=running\nNRestarts=0", '']]],
    'systemd second sample failure' => [ProcessRuntime::Systemd, [[0, '', ''], [0, '', ''], [0, "ActiveState=active\nSubState=running\nNRestarts=0\n", ''], [2, '', 'secret']]],
    'systemd second sample truncation' => [ProcessRuntime::Systemd, [[0, '', ''], [0, '', ''], [0, "ActiveState=active\nSubState=running\nNRestarts=0\n", ''], [0, "ActiveState=active\nSubState=running\nNRestarts=0\n", '', true]]],
    'Docker ownership collision' => [ProcessRuntime::Docker, [[0, "false\nprocess\n{id}\nrunning\n", '']]],
    'Docker malformed status' => [ProcessRuntime::Docker, [[0, "true\nprocess\n{id}\nsecret-state\n", '']]],
    'Docker command failure' => [ProcessRuntime::Docker, [[2, 'secret-output', 'secret-error']]],
    'Docker truncation' => [ProcessRuntime::Docker, [[0, "true\nprocess\n{id}\nrunning\n", '', true]]],
    'transport error' => [ProcessRuntime::Docker, [], true],
]);

/** @return array{NativeProcessStateInspector, NativeProcessInspectorSsh, Process} */
function native_process_inspector(
    ProcessRuntime $runtime,
    array $results,
    ?CommandDeadline $deadline = null,
): array {
    Sleep::fake();
    $node = Node::query()->create([
        'name' => fake()->unique()->word(),
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.51',
        'public_ssh_port' => 2022,
        'user' => 'nckrtl',
        'wireguard_ip' => '10.44.0.51',
    ]);
    orbit_test_set_app_placement_role($node, false);
    $project = Project::query()->create([
        'name' => fake()->word(),
        'slug' => fake()->unique()->slug(),
        'repository_url' => 'git@example.test:app.git',
        'apps' => fixture_apps(null),
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => fake()->word(),
        'environment' => 'development',
        'checkout_path' => '/home/orbit/app',
        'source_is_laravel' => false,
        'provisioning_step' => 'active',
        'status' => 'active',
    ]);
    $process = Process::query()->create([
        'owner_type' => Instance::MorphAlias,
        'owner_id' => $instance->id,
        'name' => fake()->unique()->slug(2),
        'runtime' => $runtime,
        'working_directory' => '/tmp',
        'runtime_config' => ['opaque' => 'hidden'],
        'restart_policy' => 'always',
        'desired_state' => DesiredProcessState::Running,
        'status' => LifecycleStatus::Active,
    ]);
    $ssh = new NativeProcessInspectorSsh($results);

    return [
        new NativeProcessStateInspector(
            new ProcessTargetResolver,
            $ssh,
            new NativeProcessInspectorKeys,
            new NativeProcessInspectorKnownHosts,
            new SystemdProcessRenderer,
            new DockerProcessRenderer,
            $deadline ?? new CommandDeadline,
        ),
        $ssh,
        $process,
    ];
}

function native_process_result(string $stdout = ''): CommandResult
{
    return new CommandResult(0, $stdout, '', 1, false);
}

final class NativeProcessInspectorSsh implements SshExecutor
{
    /** @var list<array{connection: SshConnection, command: RemoteCommand}> */
    public array $calls = [];

    public bool $throws = false;

    /** @param list<CommandResult> $results */
    public function __construct(
        public array $results,
    ) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->calls[] = ['connection' => $connection, 'command' => $command];

        if ($this->throws) {
            throw new RuntimeException('secret transport detail');
        }

        return array_shift($this->results) ?? throw new RuntimeException('Unexpected process inspector call.');
    }
}

final readonly class NativeProcessInspectorKeys implements SshKeyProvider
{
    public function privateKeyPath(): string
    {
        return '/managed-key';
    }

    public function publicKey(): string
    {
        return 'public';
    }
}

final readonly class NativeProcessInspectorKnownHosts implements KnownHostsStore
{
    public function path(): string
    {
        return '/pinned-hosts';
    }

    public function put(string $host, int $port, HostKey $key): void {}
}
