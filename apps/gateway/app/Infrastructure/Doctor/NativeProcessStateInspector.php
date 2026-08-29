<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctor;

use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\ProcessInspectionData;
use App\Domain\Doctor\ProcessInspectionStatus;
use App\Domain\Doctor\ProcessStateInspector;
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
use Throwable;

/**
 * @mago-expect lint:cyclomatic-complexity The inspector keeps both bounded native state parsers explicit.
 * @mago-expect lint:kan-defect The score reflects closed ownership, absence, and native state branches.
 */
final readonly class NativeProcessStateInspector implements ProcessStateInspector
{
    private const string DOCKER_INSPECT_FORMAT = '{{ index .Config.Labels "orbit.managed" }}{{ printf "\\n" }}{{ index .Config.Labels "orbit.container.kind" }}{{ printf "\\n" }}{{ index .Config.Labels "orbit.process.id" }}{{ printf "\\n" }}{{ .State.Status }}';

    /** @var list<string> */
    private const array SYSTEMD_STATES = [
        'active',
        'reloading',
        'inactive',
        'failed',
        'activating',
        'deactivating',
        'maintenance',
        'unknown',
    ];

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

    /** @mago-expect lint:excessive-parameter-list The inspector receives only its read-only SSH boundary and renderers. */
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
            $target = $this->targets->forProcess($process);
            $connection = $this->connection($target->node);

            return match ($process->runtime) {
                ProcessRuntime::Systemd => $this->inspectSystemd($process, $connection),
                ProcessRuntime::Docker => $this->inspectDocker($process, $connection),
            };
        } catch (DoctorInspectionException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new DoctorInspectionException;
        }
    }

    private function inspectSystemd(Process $process, SshConnection $connection): ProcessInspectionData
    {
        $path = $this->systemd->unitPath($process);
        $exists = $this->ssh->execute(
            $connection,
            new RemoteCommand(['sudo', 'test', '-e', $path]),
        );

        if ($this->isExactSilentResult($exists, 1)) {
            return new ProcessInspectionData(false, null);
        }
        if (! $this->isExactSilentResult($exists, 0)) {
            throw new DoctorInspectionException;
        }

        $owned = $this->ssh->execute(
            $connection,
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

        $status = $this->ssh->execute(
            $connection,
            new RemoteCommand([
                'sudo',
                'systemctl',
                'is-active',
                $this->systemd->unitName($process),
            ]),
        );

        if (
            $status->truncated
            || $status->stderr !== ''
            || ! in_array($status->exitCode, [0, 3, 4], strict: true)
        ) {
            throw new DoctorInspectionException;
        }

        $value = $this->singleLine($status->stdout);
        if (! in_array($value, self::SYSTEMD_STATES, strict: true)) {
            throw new DoctorInspectionException;
        }

        return new ProcessInspectionData(true, match ($value) {
            'active' => ProcessInspectionStatus::Active,
            'inactive' => ProcessInspectionStatus::Inactive,
            default => ProcessInspectionStatus::Other,
        });
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
        $host = $node->wireguard_address;
        if ($node->platform !== 'linux' || ! is_string($host) || $host === '') {
            throw new DoctorInspectionException;
        }

        return new SshConnection(
            $host,
            'orbit',
            22,
            $this->keys->privateKeyPath(),
            $this->knownHosts->path(),
            commandTimeout: $this->deadline->cap(30.0),
        );
    }

    private function isExactSilentResult(CommandResult $result, int $exitCode): bool
    {
        return (
            ! $result->truncated
            && $result->exitCode === $exitCode
            && $result->stdout === ''
            && $result->stderr === ''
        );
    }

    private function isDockerMissing(CommandResult $result): bool
    {
        return (
            ! $result->succeeded()
            && $result->stdout === ''
            && (str_contains($result->stderr, 'No such object') || str_contains($result->stderr, 'No such container'))
        );
    }

    private function singleLine(string $output): string
    {
        if (substr_count(haystack: $output, needle: "\n") !== 1 || ! str_ends_with($output, "\n")) {
            throw new DoctorInspectionException;
        }

        return substr(string: $output, offset: 0, length: -1);
    }
}
