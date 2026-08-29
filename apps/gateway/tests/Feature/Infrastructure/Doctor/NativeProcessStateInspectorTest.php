<?php

declare(strict_types=1);

use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\ProcessInspectionStatus;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Doctor\NativeProcessStateInspector;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\DockerProcessRenderer;
use App\Infrastructure\Processes\SystemdProcessRenderer;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\App as OrbitApp;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;

it('inspects an owned systemd unit with exact read-only commands on the selected node', function (): void {
    $deadline = new CommandDeadline(static fn (): float => 100.0);
    $deadline->start(12.5);
    [$inspector, $ssh, $process] = native_process_inspector(
        ProcessRuntime::Systemd,
        [
            native_process_result(),
            native_process_result(),
            native_process_result("active\n"),
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
            ['sudo', 'systemctl', 'is-active', $unit],
        ])
        ->and(array_column($ssh->calls, 'connection'))
        ->each(fn ($connection) => $connection->toEqual(
            new SshConnection('10.44.0.51', 'orbit', 22, '/managed-key', '/pinned-hosts', commandTimeout: 12.5),
        ));
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
            'orbit',
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
        [[0, '', ''], [0, '', ''], [3, "inactive\n", '']],
        ProcessInspectionStatus::Inactive,
    ],
    'failed systemd' => [
        ProcessRuntime::Systemd,
        [[0, '', ''], [0, '', ''], [3, "failed\n", '']],
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
        [[0, '', ''], [0, '', ''], [0, "active\n", '', true]],
    ],
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
    $node = Node::query()->create([
        'name' => fake()->unique()->word(),
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.51',
        'public_ssh_port' => 2022,
        'ssh_user' => 'root',
        'wireguard_address' => '10.44.0.51',
    ]);
    $app = OrbitApp::query()->create([
        'name' => fake()->word(),
        'slug' => fake()->unique()->slug(),
        'repository_url' => 'git@example.test:app.git',
    ]);
    $instance = Instance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => fake()->word(),
        'environment' => 'development',
        'checkout_path' => '/home/orbit/app',
        'hostname' => fake()->unique()->domainName(),
        'certificate_mode' => 'orbit-ca',
        'status' => LifecycleStatus::Active,
    ]);
    $process = Process::query()->create([
        'owner_type' => Instance::class,
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

/** @mago-expect lint:single-class-per-file Test-local fake keeps key material isolated. */
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

/** @mago-expect lint:single-class-per-file Test-local fake keeps host state isolated. */
final readonly class NativeProcessInspectorKnownHosts implements KnownHostsStore
{
    public function path(): string
    {
        return '/pinned-hosts';
    }

    public function put(string $host, int $port, \App\Infrastructure\Ssh\HostKey $key): void {}
}
