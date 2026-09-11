<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctor;

use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\ScheduleInspectionData;
use App\Domain\Doctor\ScheduleStateInspector;
use App\Domain\Schedules\ScheduleRenderer;
use App\Domain\Schedules\ScheduleTarget;
use App\Domain\Schedules\ScheduleTargetResolver;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use App\Models\Schedule;
use Throwable;

final readonly class NativeScheduleStateInspector implements ScheduleStateInspector
{
    public function __construct(
        private ScheduleTargetResolver $targets,
        private ScheduleRenderer $renderer,
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
    ) {}

    public function inspect(Schedule $schedule): ScheduleInspectionData
    {
        try {
            $target = $this->targets->forInspection($schedule);
            $fingerprints = $this->renderer->fingerprints($schedule, $target);
            $details = $this->detailFingerprints($schedule, $target);
            $result = $this->ssh->execute(
                $this->connection($target->node),
                new RemoteCommand(
                    ['sudo', 'bash', '-s', '--', $schedule->id, $target->group, $schedule->desired_timer_state->value],
                    protectedInput: ProtectedInput::fromString($this->program($fingerprints, $details)),
                    maxOutputBytes: 128,
                    timeout: 15.0,
                ),
            );

            if (! $result->succeeded() || $result->truncated || $result->stderr !== '') {
                throw new DoctorInspectionException;
            }

            $values = explode('|', rtrim($result->stdout, "\n"));
            if (count($values) !== 7 || array_any($values, static fn (string $value): bool => ! in_array($value, ['0', '1'], true))) {
                throw new DoctorInspectionException;
            }

            return new ScheduleInspectionData(...array_map(static fn (string $value): bool => $value === '1', $values));
        } catch (DoctorInspectionException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new DoctorInspectionException;
        }
    }

    public function orphanIds(Node $node, array $knownIds): array
    {
        try {
            $result = $this->ssh->execute(
                $this->connection($node),
                new RemoteCommand(
                    ['sudo', 'bash', '-s'],
                    protectedInput: ProtectedInput::fromString(<<<'BASH'
                        set -euo pipefail
                        [ ! -d /etc/orbit/schedules ] || find /etc/orbit/schedules -maxdepth 1 -type f -printf '%f\n'
                        find /etc/systemd/system -maxdepth 1 -type f \( -name 'orbit-schedule-*.service' -o -name 'orbit-schedule-*.timer' \) -printf '%f\n'
                        BASH),
                    maxOutputBytes: 131072,
                    timeout: 15.0,
                ),
            );

            if (! $result->succeeded() || $result->truncated || $result->stderr !== '') {
                throw new DoctorInspectionException;
            }

            $ids = [];
            foreach (explode("\n", rtrim($result->stdout, "\n")) as $name) {
                if (preg_match('/(?:\A|orbit-schedule-)([0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})(?:\.sh|\.service|\.timer)\z/D', $name, $matches) === 1) {
                    $ids[$matches[1]] = true;
                }
            }

            $orphans = array_values(array_diff(array_keys($ids), $knownIds));
            sort($orphans, SORT_STRING);

            return $orphans;
        } catch (DoctorInspectionException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new DoctorInspectionException;
        }
    }

    /**
     * @param  array{script: string, service: string, timer: string}  $fingerprints
     * @param  array{calendar: string, context: string, callback: string}  $details
     */
    private function program(array $fingerprints, array $details): string
    {
        return <<<BASH
            set -euo pipefail
            id="\$1"; runtime_group="\$2"; desired="\$3"
            script="/etc/orbit/schedules/\${id}.sh"
            service="/etc/systemd/system/orbit-schedule-\${id}.service"
            timer="/etc/systemd/system/orbit-schedule-\${id}.timer"
            present=1; permissions=1; specification=1; state=1; calendar=1; context=1; callback=1
            for path in "\$script" "\$service" "\$timer"; do [ -f "\$path" ] && [ ! -L "\$path" ] || present=0; done
            if [ "\$present" -eq 1 ]; then
              [ "\$(stat -c '%U:%G:%a' -- "\$script")" = "root:\${runtime_group}:750" ] || permissions=0
              [ "\$(stat -c '%U:%G:%a' -- "\$service")" = root:root:644 ] || permissions=0
              [ "\$(stat -c '%U:%G:%a' -- "\$timer")" = root:root:644 ] || permissions=0
              [ "\$(sha256sum "\$script" | cut -d ' ' -f 1)" = '{$fingerprints['script']}' ] || specification=0
              [ "\$(sha256sum "\$service" | cut -d ' ' -f 1)" = '{$fingerprints['service']}' ] || specification=0
              [ "\$(sha256sum "\$timer" | cut -d ' ' -f 1)" = '{$fingerprints['timer']}' ] || specification=0
              [ "\$(grep -E '^(User|Group|WorkingDirectory|Environment=(HOME|SHELL))=' "\$service" | sha256sum | cut -d ' ' -f 1)" = '{$details['context']}' ] || context=0
              [ "\$(grep '^OnCalendar=' "\$timer" | sha256sum | cut -d ' ' -f 1)" = '{$details['calendar']}' ] || calendar=0
              [ "\$(grep -F "/api/v1/schedules/\${id}/complete" "\$script" | sha256sum | cut -d ' ' -f 1)" = '{$details['callback']}' ] || callback=0
              if [ "\$desired" = enabled ]; then
                [ "\$(systemctl is-enabled "orbit-schedule-\${id}.timer" 2>/dev/null || true)" = enabled ] || state=0
                [ "\$(systemctl is-active "orbit-schedule-\${id}.timer" 2>/dev/null || true)" = active ] || state=0
              else
                [ "\$(systemctl is-enabled "orbit-schedule-\${id}.timer" 2>/dev/null || true)" != enabled ] || state=0
                [ "\$(systemctl is-active "orbit-schedule-\${id}.timer" 2>/dev/null || true)" != active ] || state=0
              fi
            else
              permissions=0; specification=0; state=0; calendar=0; context=0; callback=0
            fi
            printf '%s|%s|%s|%s|%s|%s|%s\n' "\$present" "\$permissions" "\$specification" "\$state" "\$calendar" "\$context" "\$callback"
            BASH;
    }

    /** @return array{calendar: string, context: string, callback: string} */
    private function detailFingerprints(Schedule $schedule, ScheduleTarget $target): array
    {
        $serviceLines = explode("\n", $this->renderer->renderService($schedule, $target));
        $context = array_values(array_filter(
            $serviceLines,
            static fn (string $line): bool => preg_match('/\A(?:User|Group|WorkingDirectory|Environment=(?:HOME|SHELL))=/', $line) === 1,
        ));
        $scriptLines = explode("\n", $this->renderer->renderScript($schedule, $target));
        $callback = array_values(array_filter(
            $scriptLines,
            static fn (string $line): bool => str_contains($line, '/api/v1/schedules/'),
        ));

        return [
            'calendar' => hash('sha256', 'OnCalendar='.$schedule->calendar."\n"),
            'context' => hash('sha256', implode("\n", $context)."\n"),
            'callback' => hash('sha256', implode("\n", $callback)."\n"),
        ];
    }

    private function connection(Node $node): SshConnection
    {
        if (! is_string($node->wireguard_ip) || $node->wireguard_ip === '') {
            throw new DoctorInspectionException;
        }

        return new SshConnection(
            $node->wireguard_ip,
            $node->user,
            22,
            $this->keys->privateKeyPath(),
            $this->knownHosts->path(),
            commandTimeout: 15.0,
        );
    }
}
