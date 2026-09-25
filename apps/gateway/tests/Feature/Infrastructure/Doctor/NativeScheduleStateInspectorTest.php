<?php

declare(strict_types=1);

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Schedules\DesiredTimerState;
use App\Domain\Schedules\ScheduleRenderer;
use App\Domain\Schedules\ScheduleTargetResolver;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Doctor\NativeScheduleStateInspector;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use App\Models\Schedule;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Tests\Support\HostBinary;
use Tests\Support\Schedules\FakeScheduleRuntimeAccountResolver;

it('reports inaccessible script traversal without changing artifacts or exposing diagnostics', function (): void {
    $node = Node::query()->create([
        'name' => 'schedule-inspector',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.91',
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.91',
    ]);
    $schedule = Schedule::query()->create([
        'target_type' => Node::class,
        'target_id' => $node->id,
        'host_node_id' => $node->id,
        'name' => 'doctor-traversal',
        'calendar' => 'daily',
        'command' => 'printf "private schedule output"',
        'timeout_seconds' => 3600,
        'desired_timer_state' => DesiredTimerState::Disabled,
        'status' => LifecycleStatus::Active,
    ]);
    $resolver = new ScheduleTargetResolver(new FakeScheduleRuntimeAccountResolver);
    $renderer = new ScheduleRenderer(new NativeScheduleInspectorFakeCertificates, 'https://10.44.0.1');
    $ssh = new NativeScheduleInspectorFakeSshExecutor;
    $inspector = new NativeScheduleStateInspector(
        $resolver,
        $renderer,
        $ssh,
        new NativeScheduleInspectorFakeKeys,
        new NativeScheduleInspectorFakeKnownHosts,
    );

    $inspection = $inspector->inspect($schedule);
    $command = $ssh->command;
    $program = stream_get_contents($command?->protectedInput?->stream()) ?: '';
    $target = $resolver->forInspection($schedule);
    $root = sys_get_temp_dir().'/orbit-native-schedule-inspector-'.bin2hex(random_bytes(6));
    $orbit = $root.'/etc/orbit';
    $systemd = $root.'/etc/systemd/system';
    $bin = $root.'/bin';
    $script = $orbit.'/schedules/'.$schedule->id.'.sh';
    $service = $systemd.'/orbit-schedule-'.$schedule->id.'.service';
    $timer = $systemd.'/orbit-schedule-'.$schedule->id.'.timer';

    try {
        mkdir(dirname($script), 0755, true);
        mkdir($systemd, 0755, true);
        mkdir($bin, 0700, true);
        file_put_contents($script, $renderer->renderScript($schedule, $target));
        file_put_contents($service, $renderer->renderService($schedule, $target));
        file_put_contents($timer, $renderer->renderTimer($schedule));
        chmod($orbit, 0700);
        chmod(dirname($script), 0755);
        chmod($script, 0750);
        chmod($service, 0644);
        chmod($timer, 0644);
        native_schedule_inspector_write_commands($bin);
        $artifactState = native_schedule_inspector_artifact_state($script, $service, $timer);
        $fixtureProgram = str_replace(
            ['/etc/orbit', '/etc/systemd/system'],
            [$orbit, $systemd],
            $program,
        );
        $arguments = array_slice($command?->arguments ?? [], 4);

        $blocked = native_schedule_inspector_run($fixtureProgram, $arguments, $bin, $orbit);
        chmod($orbit, 0711);
        $reachable = native_schedule_inspector_run($fixtureProgram, $arguments, $bin, $orbit);

        expect($inspection->permissionsMatch)
            ->toBeTrue()
            ->and($command?->arguments)
            ->toBe([
                'sudo',
                'bash',
                '-s',
                '--',
                $schedule->id,
                'orbit',
                'orbit',
                'disabled',
            ])
            ->and($blocked->getExitCode())
            ->toBe(0)
            ->and($blocked->getOutput())
            ->toBe("1|0|1|1|1|1|1\n")
            ->and($blocked->getErrorOutput())
            ->toBe('')
            ->and($reachable->getExitCode())
            ->toBe(0)
            ->and($reachable->getOutput())
            ->toBe("1|1|1|1|1|1|1\n")
            ->and($reachable->getErrorOutput())
            ->toBe('')
            ->and(native_schedule_inspector_artifact_state($script, $service, $timer))
            ->toBe($artifactState);
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
});

/** @param list<string> $arguments */
function native_schedule_inspector_run(string $program, array $arguments, string $bin, string $orbit): Process
{
    $process = new Process(
        ['bash', '-seu', '--', ...$arguments],
        env: [
            'PATH' => $bin.':'.getenv('PATH'),
            'ORBIT_TEST_PARENT' => $orbit,
        ],
    );
    $process->setInput($program);
    $process->run();

    return $process;
}

function native_schedule_inspector_write_commands(string $bin): void
{
    file_put_contents($bin.'/stat', HostBinary::expand(<<<'BASH'
        #!/bin/sh
        if [ "$1" = -c ] && [ "$2" = %U:%G:%a ]; then
            shift 2
            [ "$1" = -- ] && shift
            case "$1" in
                *.sh) printf 'root:orbit:750\n' ;;
                *) printf 'root:root:644\n' ;;
            esac
            exit 0
        fi
        exec {{host:stat}} "$@"
        BASH));
    chmod($bin.'/stat', 0700);
    file_put_contents($bin.'/systemctl', <<<'BASH'
        #!/bin/sh
        case "$1" in
            is-enabled) printf 'disabled\n' ;;
            is-active) printf 'inactive\n' ;;
            *) exit 1 ;;
        esac
        BASH);
    chmod($bin.'/systemctl', 0700);
    file_put_contents($bin.'/sudo', HostBinary::expand(<<<'BASH'
        #!/bin/sh
        [ "$1" = -u ] || exit 97
        shift 2
        [ "$1" = -- ] && shift
        case "$({{host:stat}} -c %a -- "$ORBIT_TEST_PARENT")" in
            *[1357]) exec "$@" ;;
            *) exit 1 ;;
        esac
        BASH));
    chmod($bin.'/sudo', 0700);
}

/** @return array<string, array{mode: string, hash: string}> */
function native_schedule_inspector_artifact_state(string ...$paths): array
{
    $state = [];
    foreach ($paths as $path) {
        $state[$path] = [
            'mode' => substr(sprintf('%o', fileperms($path)), -3),
            'hash' => hash_file('sha256', $path),
        ];
    }

    return $state;
}

final class NativeScheduleInspectorFakeSshExecutor implements SshExecutor
{
    public ?RemoteCommand $command = null;

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->command = $command;

        return new CommandResult(0, "1|1|1|1|1|1|1\n", '', 1, false);
    }
}

final class NativeScheduleInspectorFakeKeys implements SshKeyProvider
{
    public function privateKeyPath(): string
    {
        return '/orbit/ssh/id_ed25519';
    }

    public function publicKey(): string
    {
        return 'ssh-ed25519 test';
    }
}

final class NativeScheduleInspectorFakeKnownHosts implements KnownHostsStore
{
    public function path(): string
    {
        return '/orbit/ssh/known_hosts';
    }

    public function put(string $host, int $port, HostKey $key): void {}
}

final class NativeScheduleInspectorFakeCertificates implements LeafCertificateSigner
{
    public function sign(string $domain, string $certificateRequest): string
    {
        throw new LogicException('Signing is not part of Schedule inspection tests.');
    }

    public function rootCertificate(): string
    {
        return 'TEST ROOT CERTIFICATE';
    }
}
