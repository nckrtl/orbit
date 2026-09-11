<?php

declare(strict_types=1);

namespace App\Infrastructure\Schedules;

use App\Data\Schedules\ScheduleLogsData;
use App\Domain\Schedules\DesiredTimerState;
use App\Domain\Schedules\ScheduleErrorCode;
use App\Domain\Schedules\ScheduleOperationException;
use App\Domain\Schedules\ScheduleRenderer;
use App\Domain\Schedules\ScheduleRuntimeManager;
use App\Domain\Schedules\ScheduleTarget;
use App\Domain\Schedules\ScheduleTargetResolver;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Schedule;
use SensitiveParameter;
use Throwable;

final readonly class RemoteScheduleRuntimeManager implements ScheduleRuntimeManager
{
    private const int MAX_LOG_BYTES = 1_048_576;

    public function __construct(
        private ScheduleTargetResolver $targets,
        private ScheduleRenderer $renderer,
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
    ) {}

    public function install(#[SensitiveParameter] Schedule $schedule): void
    {
        $target = $this->targets->forInstallation($schedule);

        if (! $target->isProduction() || $schedule->desired_timer_state === DesiredTimerState::Enabled) {
            $this->assertRunnable($schedule, $target);
        }

        try {
            $program = $this->installProgram(
                $schedule,
                $target,
                $this->renderer->renderScript($schedule, $target),
                $this->renderer->renderService($schedule, $target),
                $this->renderer->renderTimer($schedule),
            );
        } catch (Throwable $exception) {
            throw $this->failure(
                'render',
                ScheduleErrorCode::InstallFailed,
                'Schedule installation failed.',
                $exception,
            );
        }
        $result = $this->execute(
            $schedule,
            $target,
            ['sudo', 'bash', '-s', '--', $schedule->id, $target->group, $schedule->desired_timer_state->value],
            protected: $program,
            timeout: 90.0,
        );

        if ($result->succeeded()) {
            return;
        }

        throw match ($result->exitCode) {
            42 => $this->failure('inspect-artifacts', ScheduleErrorCode::ArtifactConflict, 'Schedule artifacts conflict.'),
            43 => $this->failure('validate-calendar', ScheduleErrorCode::CalendarInvalid, 'The Schedule calendar is invalid.'),
            91 => $this->failure('rollback', ScheduleErrorCode::RollbackFailed, 'Schedule installation rollback failed.'),
            255 => $this->failure('connect', ScheduleErrorCode::NodeUnreachable, 'The Schedule host Node is unreachable.'),
            default => $this->failure('install', ScheduleErrorCode::InstallFailed, 'Schedule installation failed.'),
        };
    }

    public function activate(#[SensitiveParameter] Schedule $schedule): void
    {
        $target = $this->targets->forSchedule($schedule);
        $this->assertRunnable($schedule, $target);
        $result = $this->execute(
            $schedule,
            $target,
            ['sudo', 'bash', '-s', '--', $schedule->id],
            protected: $this->activationProgram(),
            timeout: 45.0,
        );

        if ($result->succeeded()) {
            return;
        }

        throw match ($result->exitCode) {
            91 => $this->failure('activate-rollback', ScheduleErrorCode::RollbackFailed, 'Schedule activation rollback failed.'),
            255 => $this->failure('connect', ScheduleErrorCode::NodeUnreachable, 'The Schedule host Node is unreachable.'),
            default => $this->failure('activate', ScheduleErrorCode::ActivationFailed, 'Schedule activation failed.'),
        };
    }

    public function run(#[SensitiveParameter] Schedule $schedule): void
    {
        $target = $this->targets->forSchedule($schedule);
        $this->assertRunnable($schedule, $target);
        $result = $this->execute(
            $schedule,
            $target,
            ['sudo', 'systemctl', 'start', '--no-block', $this->renderer->serviceName($schedule)],
            timeout: 15.0,
        );

        if (! $result->succeeded()) {
            throw $this->remoteFailure($result, 'run', ScheduleErrorCode::RunFailed, 'Schedule run failed.');
        }
    }

    public function logs(#[SensitiveParameter] Schedule $schedule, int $lines): ScheduleLogsData
    {
        if ($lines < 1 || $lines > 1000) {
            throw $this->failure('logs-validation', ScheduleErrorCode::LogsFailed, 'The Schedule log limit is invalid.');
        }

        $target = $this->targets->forSchedule($schedule);
        $result = $this->execute(
            $schedule,
            $target,
            [
                'sudo',
                'journalctl',
                '--unit',
                $this->renderer->serviceName($schedule),
                '--lines',
                (string) $lines,
                '--no-pager',
                '--output',
                'cat',
                '--reverse',
            ],
            timeout: 10.0,
            maxOutputBytes: self::MAX_LOG_BYTES + 8192,
        );

        if (! $result->succeeded()) {
            throw $this->remoteFailure($result, 'logs', ScheduleErrorCode::LogsFailed, 'Schedule logs could not be read.');
        }

        $truncated = $result->truncated || strlen($result->stdout) > self::MAX_LOG_BYTES;
        $output = $result->stdout;

        if (strlen($output) > self::MAX_LOG_BYTES) {
            $output = substr($output, 0, self::MAX_LOG_BYTES);
        }

        if ($truncated && ! str_ends_with($output, "\n")) {
            $lastNewline = strrpos($output, "\n");
            $output = $lastNewline === false ? '' : substr($output, 0, $lastNewline + 1);
        }

        $entries = $output === '' ? [] : explode("\n", rtrim($output, "\n"));
        $output = $entries === [] ? '' : implode("\n", array_reverse($entries))."\n";

        return new ScheduleLogsData($output, $truncated);
    }

    public function remove(#[SensitiveParameter] Schedule $schedule, bool $cascade): bool
    {
        $target = $this->targets->forRemoval($schedule);
        $result = $this->execute(
            $schedule,
            $target,
            ['sudo', 'bash', '-s', '--', $schedule->id, $target->group, $cascade ? 'cascade' : 'standalone'],
            protected: $this->removalProgram(),
            timeout: 60.0,
        );

        if ($result->exitCode === 75 && ! $cascade) {
            return false;
        }

        if (! $result->succeeded()) {
            throw match ($result->exitCode) {
                42 => $this->failure('inspect-artifacts', ScheduleErrorCode::ArtifactConflict, 'Schedule artifacts conflict.'),
                255 => $this->failure('connect', ScheduleErrorCode::NodeUnreachable, 'The Schedule host Node is unreachable.'),
                default => $this->failure('remove', ScheduleErrorCode::RemoveFailed, 'Schedule removal failed.'),
            };
        }

        return true;
    }

    private function assertRunnable(Schedule $schedule, ScheduleTarget $target): void
    {
        $result = $this->execute(
            $schedule,
            $target,
            ['sudo', '-u', $target->user, 'test', '-d', $target->workingDirectory],
            timeout: 10.0,
        );

        if (! $result->succeeded()) {
            throw $this->remoteFailure(
                $result,
                'resolve-release',
                ScheduleErrorCode::TargetUnavailable,
                'The Schedule target is unavailable.',
            );
        }
    }

    private function installProgram(
        Schedule $schedule,
        ScheduleTarget $target,
        #[SensitiveParameter] string $script,
        string $service,
        string $timer,
    ): string {
        $calendar = base64_encode($schedule->calendar);
        $script = base64_encode($script);
        $service = base64_encode($service);
        $timer = base64_encode($timer);

        return <<<BASH
            set -Eeuo pipefail
            id="\$1"; runtime_group="\$2"; desired="\$3"
            script_path="/etc/orbit/schedules/\${id}.sh"
            service_path="/etc/systemd/system/orbit-schedule-\${id}.service"
            timer_path="/etc/systemd/system/orbit-schedule-\${id}.timer"
            timer="orbit-schedule-\${id}.timer"
            work="\$(mktemp -d /tmp/orbit-schedule.XXXXXX)"
            cleanup() { rm -rf -- "\$work"; }
            trap cleanup EXIT
            exec 9>"/run/lock/orbit-schedule-\${id}.lock"
            flock --wait 30 9
            existed_script=0; existed_service=0; existed_timer=0
            enabled=disabled; active=inactive
            own() {
              local path="\$1" expected="\$2" marker="\$3"
              [ ! -e "\$path" ] && return 1
              [ -f "\$path" ] && [ ! -L "\$path" ] || exit 42
              [ "\$(stat -c '%U:%G:%a' -- "\$path")" = "\$expected" ] || exit 42
              grep -Fqx -- "\$marker" "\$path" || exit 42
              return 0
            }
            if own "\$script_path" "root:\${runtime_group}:750" "# X-Orbit-Schedule-ID=\${id}"; then existed_script=1; cp -a -- "\$script_path" "\$work/script"; fi
            if own "\$service_path" "root:root:644" "X-Orbit-Schedule-ID=\${id}"; then existed_service=1; cp -a -- "\$service_path" "\$work/service"; fi
            if own "\$timer_path" "root:root:644" "X-Orbit-Schedule-ID=\${id}"; then existed_timer=1; cp -a -- "\$timer_path" "\$work/timer"; fi
            enabled="\$(systemctl is-enabled "\$timer" 2>/dev/null || true)"
            active="\$(systemctl is-active "\$timer" 2>/dev/null || true)"
            rollback() {
              set +e
              systemctl disable --now "\$timer" >/dev/null 2>&1 || true
              rm -f -- "/etc/orbit/schedule-candidates/\${id}.sh" "/etc/orbit/schedule-candidates/orbit-schedule-\${id}.service" "/etc/orbit/schedule-candidates/orbit-schedule-\${id}.timer"
              if [ "\$existed_script" -eq 1 ]; then cp -a -- "\$work/script" "\$script_path"; else rm -f -- "\$script_path"; fi
              if [ "\$existed_service" -eq 1 ]; then cp -a -- "\$work/service" "\$service_path"; else rm -f -- "\$service_path"; fi
              if [ "\$existed_timer" -eq 1 ]; then cp -a -- "\$work/timer" "\$timer_path"; else rm -f -- "\$timer_path"; fi
              systemctl daemon-reload || return 1
              if [ "\$existed_timer" -eq 1 ]; then
                if [ "\$enabled" = enabled ]; then systemctl enable "\$timer" || return 1; else systemctl disable "\$timer" || return 1; fi
                if [ "\$active" = active ]; then systemctl start "\$timer" || return 1; else systemctl stop "\$timer" || return 1; fi
              else
                systemctl reset-failed "\$timer" >/dev/null 2>&1 || true
              fi
              rm -rf -- "\$work"
            }
            failed() { trap - ERR; rollback || exit 91; exit 90; }
            trap failed ERR
            printf '%s' '{$calendar}' | base64 -d > "\$work/calendar"
            systemd-analyze calendar "\$(cat "\$work/calendar")" >/dev/null 2>&1 || exit 43
            install -d -o root -g root -m 0755 /etc/orbit/schedules /etc/orbit/schedule-candidates
            printf '%s' '{$script}' | base64 -d > "\$work/script.new"
            printf '%s' '{$service}' | base64 -d > "\$work/service.new"
            printf '%s' '{$timer}' | base64 -d > "\$work/timer.new"
            install -o root -g "\$runtime_group" -m 0750 "\$work/script.new" "/etc/orbit/schedule-candidates/\${id}.sh"
            install -o root -g root -m 0644 "\$work/service.new" "/etc/orbit/schedule-candidates/orbit-schedule-\${id}.service"
            install -o root -g root -m 0644 "\$work/timer.new" "/etc/orbit/schedule-candidates/orbit-schedule-\${id}.timer"
            systemctl disable --now "\$timer" >/dev/null 2>&1 || true
            install -o root -g "\$runtime_group" -m 0750 "/etc/orbit/schedule-candidates/\${id}.sh" "\$script_path"
            systemd-analyze verify "/etc/orbit/schedule-candidates/orbit-schedule-\${id}.service" "/etc/orbit/schedule-candidates/orbit-schedule-\${id}.timer" >/dev/null
            rm -f -- "/etc/orbit/schedule-candidates/\${id}.sh"
            mv -- "/etc/orbit/schedule-candidates/orbit-schedule-\${id}.service" "\$service_path"
            mv -- "/etc/orbit/schedule-candidates/orbit-schedule-\${id}.timer" "\$timer_path"
            systemctl daemon-reload
            if [ "\$desired" = enabled ]; then
              systemctl enable --now "\$timer"
              [ "\$(systemctl is-enabled "\$timer")" = enabled ]
              [ "\$(systemctl is-active "\$timer")" = active ]
            else
              systemctl disable --now "\$timer"
              [ "\$(systemctl is-enabled "\$timer" 2>/dev/null || true)" != enabled ]
              [ "\$(systemctl is-active "\$timer" 2>/dev/null || true)" != active ]
            fi
            trap - ERR
            rm -rf -- "\$work"
            BASH;
    }

    private function activationProgram(): string
    {
        return <<<'BASH'
            set -Eeuo pipefail
            id="$1"; timer="orbit-schedule-${id}.timer"
            exec 9>"/run/lock/orbit-schedule-${id}.lock"; flock --wait 30 9
            enabled="$(systemctl is-enabled "$timer" 2>/dev/null || true)"
            active="$(systemctl is-active "$timer" 2>/dev/null || true)"
            rollback() {
              set +e
              if [ "$enabled" = enabled ]; then systemctl enable "$timer" || return 1; else systemctl disable "$timer" || return 1; fi
              if [ "$active" = active ]; then systemctl start "$timer" || return 1; else systemctl stop "$timer" || return 1; fi
            }
            failed() { trap - ERR; rollback || exit 91; exit 90; }
            trap failed ERR
            systemctl enable --now "$timer"
            [ "$(systemctl is-enabled "$timer")" = enabled ]
            [ "$(systemctl is-active "$timer")" = active ]
            trap - ERR
            BASH;
    }

    private function removalProgram(): string
    {
        return <<<'BASH'
            set -Eeuo pipefail
            id="$1"; runtime_group="$2"; mode="$3"
            script_path="/etc/orbit/schedules/${id}.sh"
            service_path="/etc/systemd/system/orbit-schedule-${id}.service"
            timer_path="/etc/systemd/system/orbit-schedule-${id}.timer"
            service="orbit-schedule-${id}.service"; timer="orbit-schedule-${id}.timer"
            exec 9>"/run/lock/orbit-schedule-${id}.lock"; flock --wait 30 9
            own() {
              local path="$1" expected="$2" marker="$3"
              [ ! -e "$path" ] && return 0
              [ -f "$path" ] && [ ! -L "$path" ] || exit 42
              [ "$(stat -c '%U:%G:%a' -- "$path")" = "$expected" ] || exit 42
              grep -Fqx -- "$marker" "$path" || exit 42
            }
            own "$script_path" "root:${runtime_group}:750" "# X-Orbit-Schedule-ID=${id}"
            own "$service_path" "root:root:644" "X-Orbit-Schedule-ID=${id}"
            own "$timer_path" "root:root:644" "X-Orbit-Schedule-ID=${id}"
            systemctl disable --now "$timer" >/dev/null 2>&1 || true
            if [ "$mode" = standalone ]; then
              service_state="$(systemctl is-active "$service" 2>/dev/null || true)"
              case "$service_state" in ''|inactive|failed|unknown) ;; *) exit 75 ;; esac
            fi
            rm -f -- "$timer_path" "$service_path" "$script_path"
            systemctl daemon-reload
            [ ! -e "$timer_path" ] && [ ! -e "$service_path" ] && [ ! -e "$script_path" ]
            BASH;
    }

    /** @param list<string> $arguments */
    private function execute(
        Schedule $schedule,
        ScheduleTarget $target,
        array $arguments,
        ?string $protected = null,
        float $timeout = 30.0,
        ?int $maxOutputBytes = null,
    ): CommandResult {
        return $this->ssh->execute(
            $this->connection($target),
            new RemoteCommand(
                $arguments,
                protectedInput: $protected === null ? null : ProtectedInput::fromString($protected),
                maxOutputBytes: $maxOutputBytes,
                timeout: $timeout,
            ),
        );
    }

    private function connection(ScheduleTarget $target): SshConnection
    {
        $host = $target->node->wireguard_ip;

        if (! is_string($host) || $host === '') {
            throw $this->failure('connect', ScheduleErrorCode::NodeUnreachable, 'The Schedule host Node is unreachable.');
        }

        return new SshConnection(
            $host,
            $target->node->user,
            22,
            $this->keys->privateKeyPath(),
            $this->knownHosts->path(),
        );
    }

    private function remoteFailure(
        CommandResult $result,
        string $step,
        ScheduleErrorCode $error,
        string $message,
    ): ScheduleOperationException {
        return $this->failure(
            $step,
            $result->exitCode === 255 ? ScheduleErrorCode::NodeUnreachable : $error,
            $result->exitCode === 255 ? 'The Schedule host Node is unreachable.' : $message,
        );
    }

    private function failure(
        string $step,
        ScheduleErrorCode $error,
        string $message,
        ?Throwable $previous = null,
    ): ScheduleOperationException {
        return new ScheduleOperationException($step, $error, $message, previous: $previous);
    }
}
