<?php

declare(strict_types=1);

namespace App\Domain\Schedules;

use App\Domain\Certificates\LeafCertificateSigner;
use App\Models\Schedule;
use InvalidArgumentException;
use SensitiveParameter;

final readonly class ScheduleRenderer
{
    public function __construct(
        private LeafCertificateSigner $certificates,
        private string $callbackBase = 'https://gateway.orbit',
    ) {}

    public function scriptPath(Schedule $schedule): string
    {
        return '/etc/orbit/schedules/'.$this->id($schedule).'.sh';
    }

    public function serviceName(Schedule $schedule): string
    {
        return 'orbit-schedule-'.$this->id($schedule).'.service';
    }

    public function timerName(Schedule $schedule): string
    {
        return 'orbit-schedule-'.$this->id($schedule).'.timer';
    }

    public function servicePath(Schedule $schedule): string
    {
        return '/etc/systemd/system/'.$this->serviceName($schedule);
    }

    public function timerPath(Schedule $schedule): string
    {
        return '/etc/systemd/system/'.$this->timerName($schedule);
    }

    public function renderScript(#[SensitiveParameter] Schedule $schedule, ScheduleTarget $target): string
    {
        $shellFlag = $target->loginShell ? '-lc' : '-c';
        $callback = rtrim($this->callbackBase, '/')
            .'/api/v1/schedules/'.$this->id($schedule).'/complete';
        $rootCertificate = base64_encode($this->certificates->rootCertificate());

        return implode("\n", [
            '#!/bin/bash',
            '# X-Orbit-Schedule-ID='.$this->id($schedule),
            'set -u',
            'case "${1:-}" in',
            '  run)',
            '    export HOME='.$this->quote($target->home),
            '    export USER='.$this->quote($target->user),
            '    export LOGNAME='.$this->quote($target->user),
            '    exec '.$this->quote($target->shell).' '.$shellFlag.' '.$this->quote($schedule->command),
            '    ;;',
            '  complete)',
            '    if [ "${SERVICE_RESULT:-failed}" = "success" ]; then orbit_status=success; else orbit_status=error; fi',
            '    /usr/bin/curl --fail --silent --show-error --max-time 10 --request POST \\',
            '      --cacert <(/usr/bin/printf %s '.$this->quote($rootCertificate).' | /usr/bin/base64 --decode) \\',
            '      --header "Content-Type: application/json" \\',
            '      --data "{\\"status\\":\\"${orbit_status}\\"}" \\',
            '      '.$this->quote($callback).' >/dev/null 2>&1 || true',
            '    ;;',
            '  *) exit 64 ;;',
            'esac',
            '',
        ]);
    }

    public function renderService(Schedule $schedule, ScheduleTarget $target): string
    {
        return implode("\n", [
            '[Unit]',
            'Description=Orbit Schedule '.$schedule->id,
            'X-Orbit-Schedule-ID='.$schedule->id,
            'After=network-online.target',
            'Wants=network-online.target',
            '',
            '[Service]',
            'Type=oneshot',
            'User='.$target->user,
            'Group='.$target->group,
            'WorkingDirectory='.$this->directive($target->workingDirectory),
            'Environment=HOME='.$this->directive($target->home),
            'Environment=SHELL='.$this->directive($target->shell),
            'ExecStart='.$this->scriptPath($schedule).' run',
            'ExecStopPost='.$this->scriptPath($schedule).' complete',
            'TimeoutStartSec='.$schedule->timeout_seconds,
            '',
        ]);
    }

    public function renderTimer(Schedule $schedule): string
    {
        return implode("\n", [
            '[Unit]',
            'Description=Orbit Schedule timer '.$schedule->id,
            'X-Orbit-Schedule-ID='.$schedule->id,
            '',
            '[Timer]',
            'OnCalendar='.$schedule->calendar,
            'Persistent=true',
            'Unit='.$this->serviceName($schedule),
            '',
            '[Install]',
            'WantedBy=timers.target',
            '',
        ]);
    }

    /** @return array{script: string, service: string, timer: string} */
    public function fingerprints(Schedule $schedule, ScheduleTarget $target): array
    {
        return [
            'script' => hash('sha256', $this->renderScript($schedule, $target)),
            'service' => hash('sha256', $this->renderService($schedule, $target)),
            'timer' => hash('sha256', $this->renderTimer($schedule)),
        ];
    }

    private function id(Schedule $schedule): string
    {
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $schedule->id) !== 1) {
            throw new InvalidArgumentException('A Schedule needs a persisted UUID before rendering.');
        }

        return $schedule->id;
    }

    private function quote(string $value): string
    {
        return "'".str_replace("'", "'\"'\"'", $value)."'";
    }

    private function directive(string $value): string
    {
        return str_replace(
            ['%', "\n", "\r"],
            ['%%', '', ''],
            $value,
        );
    }
}
