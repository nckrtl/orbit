<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

/**
 * Renders the Gateway release units ([Automatic releases](/reference/gateway-recovery#automatic-releases)):
 *
 * - `orbit-gateway-release.timer` starts `orbit-gateway-release.service` every minute. The service
 *   runs `gateway:release:auto` once.
 * - `orbit-gateway-release-run@.service` runs `gateway:release:run <record>` for one release that an
 *   operator requested through the API.
 *
 * Both run as the Gateway account from the stable application path, so each run uses the release
 * that is current when it starts. Neither is the scheduler or PHP-FPM, so the runtime handoff of
 * the release they run never stops them. A release can run `composer install`, so they get one hour.
 *
 * `ExecStopPost` runs `gateway:release:settle` after the main process exits for any reason, so a
 * release that was killed or timed out ends its record as `interrupted` at once. Each unit sets
 * `PATH`, `HOME`, and `LANG`, so `php`, `git`, and `composer` resolve as in a login shell.
 */
final readonly class GatewayReleaseUnitRenderer
{
    public const int TimeoutSeconds = 3600;

    public const string Path = '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';

    public function serviceName(): string
    {
        return 'orbit-gateway-release.service';
    }

    public function timerName(): string
    {
        return 'orbit-gateway-release.timer';
    }

    public function runTemplateName(): string
    {
        return 'orbit-gateway-release-run@.service';
    }

    public function runUnitName(int $record): string
    {
        return 'orbit-gateway-release-run@'.$record.'.service';
    }

    public function path(string $unit, string $unitDirectory = '/etc/systemd/system'): string
    {
        return rtrim($unitDirectory, '/').'/'.$unit;
    }

    public function renderService(string $phpBinary, string $artisan, string $orbitHome, string $workingDirectory, string $user): string
    {
        return $this->renderOneshot(
            'Orbit Gateway automatic release',
            [$phpBinary, $artisan, 'gateway:release:auto'],
            '',
            [$phpBinary, $artisan, 'gateway:release:settle'],
            $orbitHome,
            $workingDirectory,
            $user,
        );
    }

    public function renderRunTemplate(string $phpBinary, string $artisan, string $orbitHome, string $workingDirectory, string $user): string
    {
        return $this->renderOneshot(
            'Orbit Gateway release %i',
            [$phpBinary, $artisan, 'gateway:release:run'],
            ' %i',
            [$phpBinary, $artisan, 'gateway:release:settle'],
            $orbitHome,
            $workingDirectory,
            $user,
        );
    }

    public function renderTimer(): string
    {
        return implode("\n", [
            '[Unit]',
            'Description=Orbit Gateway automatic release timer',
            '',
            '[Timer]',
            'OnCalendar=*-*-* *:*:00',
            'AccuracySec=1s',
            'RandomizedDelaySec=10s',
            'Persistent=false',
            'Unit='.$this->serviceName(),
            '',
            '[Install]',
            'WantedBy=timers.target',
            '',
        ]);
    }

    /**
     * @param  non-empty-list<string>  $command
     * @param  string  $specifiers  unquoted systemd specifiers appended to both command lines
     * @param  non-empty-list<string>  $settle  the command that ends a dead record after the main process exits
     */
    private function renderOneshot(
        string $description,
        array $command,
        string $specifiers,
        array $settle,
        string $orbitHome,
        string $workingDirectory,
        string $user,
    ): string {
        return implode("\n", [
            '[Unit]',
            'Description='.$description,
            'After=network-online.target',
            'Wants=network-online.target',
            '',
            '[Service]',
            'Type=oneshot',
            'User='.$user,
            'WorkingDirectory='.$this->escapeDirectivePath($workingDirectory),
            'Environment=ORBIT_HOME='.$this->escapeDirectivePath($orbitHome),
            'Environment=HOME='.$this->escapeDirectivePath('/home/'.$user),
            'Environment=PATH='.self::Path,
            'Environment=LANG=C.UTF-8',
            'ExecStart='.implode(' ', array_map($this->quoteArgument(...), $command)).$specifiers,
            'ExecStopPost='.implode(' ', array_map($this->quoteArgument(...), $settle)).$specifiers,
            'TimeoutStartSec='.self::TimeoutSeconds,
            '',
        ]);
    }

    private function quoteArgument(string $argument): string
    {
        return '"'.str_replace(['\\', '"', '$', '%'], ['\\\\', '\\"', '$$', '%%'], $argument).'"';
    }

    private function escapeDirectivePath(string $value): string
    {
        $escaped = preg_replace_callback(
            '/[\x00-\x20"\'$%\\\\\x7F]/',
            static fn (array $match): string => $match[0] === '%'
                ? '%%'
                : sprintf('\\x%02x', ord($match[0])),
            $value,
        );

        return $escaped ?? $value;
    }
}
