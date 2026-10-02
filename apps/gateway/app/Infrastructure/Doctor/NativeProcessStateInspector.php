<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctor;

use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\ProcessInspectionData;
use App\Domain\Doctor\ProcessInspectionStatus;
use App\Domain\Doctor\ProcessStateInspector;
use App\Domain\Doctor\SystemdProcessObservationData;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessTargetResolver;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\DockerProcessRenderer;
use App\Infrastructure\Processes\SystemdProcessRenderer;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Support\Sleep;
use Throwable;

final readonly class NativeProcessStateInspector implements ProcessStateInspector
{
    private const string DOCKER_INSPECT_FORMAT = '{{ index .Config.Labels "orbit.managed" }}{{ printf "\\n" }}{{ index .Config.Labels "orbit.container.kind" }}{{ printf "\\n" }}{{ index .Config.Labels "orbit.process.id" }}{{ printf "\\n" }}{{ .State.Status }}';

    private const float SYSTEMD_INSPECTION_SECONDS = 10.0;

    private const int RESTART_OBSERVATION_SECONDS = 2;

    /** @var list<string> */
    private const array DOCKER_STATES = [
        'created',
        'running',
        'paused',
        'restarting',
        'removing',
        'exited',
        'dead',
    ];

    public function __construct(
        private ProcessTargetResolver $targets,
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
        private SystemdProcessRenderer $systemd,
        private DockerProcessRenderer $docker,
        private CommandDeadline $deadline,
    ) {}

    public function inspect(Process $process): ProcessInspectionData
    {
        try {
            $target = $this->targets->forInspection($process);

            return match ($process->runtime) {
                ProcessRuntime::Systemd => $this->deadline->withinForwardWork(
                    self::SYSTEMD_INSPECTION_SECONDS,
                    fn (): ProcessInspectionData => $this->inspectSystemd($process, $target->node),
                ),
                ProcessRuntime::Docker => $this->inspectDocker($process, $this->connection($target->node)),
            };
        } catch (DoctorInspectionException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new DoctorInspectionException;
        }
    }

    private function inspectSystemd(Process $process, Node $node): ProcessInspectionData
    {
        $path = $this->systemd->unitPath($process);
        $exists = $this->ssh->execute(
            $this->connection($node),
            new RemoteCommand(['sudo', 'test', '-e', $path]),
        );

        if ($this->isExactSilentResult($exists, 1)) {
            return new ProcessInspectionData(false, null);
        }
        if (! $this->isExactSilentResult($exists, 0)) {
            throw new DoctorInspectionException;
        }

        $owned = $this->ssh->execute(
            $this->connection($node),
            new RemoteCommand([
                'sudo',
                'grep',
                '-Fqx',
                '--',
                "X-Orbit-Process-ID={$process->id}",
                $path,
            ]),
        );
        if (! $this->isExactSilentResult($owned, 0)) {
            throw new DoctorInspectionException;
        }

        $before = $this->systemdObservation($process, $node);
        $observations = [$before];
        if ($process->desired_state === DesiredProcessState::Running && ! $before->isAutoRestart()) {
            if ($this->deadline->cap(self::SYSTEMD_INSPECTION_SECONDS) <= self::RESTART_OBSERVATION_SECONDS) {
                throw new DoctorInspectionException;
            }

            Sleep::for(self::RESTART_OBSERVATION_SECONDS)->seconds();
            $observations[] = $this->systemdObservation($process, $node);
        }

        return new ProcessInspectionData(true, array_last($observations)->status(), $observations);
    }

    private function systemdObservation(Process $process, Node $node): SystemdProcessObservationData
    {
        $result = $this->ssh->execute(
            $this->connection($node),
            new RemoteCommand([
                'sudo',
                'systemctl',
                'show',
                '--property=ActiveState,SubState,NRestarts',
                '--',
                $this->systemd->unitName($process),
            ]),
        );
        if (! $result->succeeded() || $result->truncated || $result->stderr !== '') {
            throw new DoctorInspectionException;
        }

        $lines = explode("\n", $result->stdout);
        if (array_pop($lines) !== '' || count($lines) < 2 || count($lines) > 3) {
            throw new DoctorInspectionException;
        }

        $properties = [];
        foreach ($lines as $line) {
            $parts = explode('=', $line, 2);
            if (
                count($parts) !== 2
                || ! in_array($parts[0], ['ActiveState', 'SubState', 'NRestarts'], strict: true)
                || array_key_exists($parts[0], $properties)
            ) {
                throw new DoctorInspectionException;
            }
            $properties[$parts[0]] = $parts[1];
        }

        if (! isset($properties['ActiveState'], $properties['SubState'])) {
            throw new DoctorInspectionException;
        }

        $restarts = $properties['NRestarts'] ?? '';
        if ($restarts !== '' && (preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D', $restarts) !== 1 || (int) $restarts > 4294967295)) {
            throw new DoctorInspectionException;
        }

        return new SystemdProcessObservationData(
            $properties['ActiveState'],
            $properties['SubState'],
            $restarts === '' ? null : (int) $restarts,
        );
    }

    private function inspectDocker(Process $process, SshConnection $connection): ProcessInspectionData
    {
        $result = $this->ssh->execute(
            $connection,
            new RemoteCommand([
                'sudo',
                'docker',
                'container',
                'inspect',
                '--format',
                self::DOCKER_INSPECT_FORMAT,
                $this->docker->containerName($process),
            ]),
        );

        if ($result->truncated) {
            throw new DoctorInspectionException;
        }
        if ($this->isDockerMissing($result)) {
            return new ProcessInspectionData(false, null);
        }
        if (! $result->succeeded() || $result->stderr !== '') {
            throw new DoctorInspectionException;
        }

        $values = explode("\n", $result->stdout);
        if (array_pop($values) !== '' || count($values) !== 4) {
            throw new DoctorInspectionException;
        }
        [$managed, $kind, $ownerId, $status] = $values;
        if (
            $managed !== 'true'
            || $kind !== 'process'
            || $ownerId !== (string) $process->id
            || ! in_array($status, self::DOCKER_STATES, strict: true)
        ) {
            throw new DoctorInspectionException;
        }

        return new ProcessInspectionData(true, match ($status) {
            'running' => ProcessInspectionStatus::Running,
            'created' => ProcessInspectionStatus::Created,
            'exited' => ProcessInspectionStatus::Exited,
            default => ProcessInspectionStatus::Other,
        });
    }

    private function connection(Node $node): SshConnection
    {
        $host = $node->wireguard_ip;
        if ($node->platform !== 'linux' || ! is_string($host) || $host === '') {
            throw new DoctorInspectionException;
        }

        return new SshConnection(
            $host,
            $node->user,
            22,
            $this->keys->privateKeyPath(),
            $this->knownHosts->path(),
            commandTimeout: $this->deadline->cap(30.0),
        );
    }

    private function isExactSilentResult(CommandResult $result, int $exitCode): bool
    {
        return
            ! $result->truncated
            && $result->exitCode === $exitCode
            && $result->stdout === ''
            && $result->stderr === '';
    }

    private function isDockerMissing(CommandResult $result): bool
    {
        return
            ! $result->succeeded()
            && $result->stdout === ''
            && (str_contains($result->stderr, 'No such object') || str_contains($result->stderr, 'No such container'));
    }
}
