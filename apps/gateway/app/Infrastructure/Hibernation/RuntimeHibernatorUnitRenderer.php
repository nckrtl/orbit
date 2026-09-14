<?php

declare(strict_types=1);

namespace App\Infrastructure\Hibernation;

use App\Domain\Hibernation\RuntimeHibernation;

final readonly class RuntimeHibernatorUnitRenderer
{
    public function serviceName(): string
    {
        return 'orbit-runtime-hibernator.service';
    }

    public function timerName(): string
    {
        return 'orbit-runtime-hibernator.timer';
    }

    public function servicePath(string $unitDirectory = '/etc/systemd/system'): string
    {
        return rtrim($unitDirectory, '/').'/'.$this->serviceName();
    }

    public function timerPath(string $unitDirectory = '/etc/systemd/system'): string
    {
        return rtrim($unitDirectory, '/').'/'.$this->timerName();
    }

    public function renderService(
        string $phpBinary,
        string $artisan,
        string $orbitHome,
        string $workingDirectory,
    ): string {
        return implode("\n", [
            '[Unit]',
            'Description=Orbit runtime hibernator',
            'After=network-online.target',
            'Wants=network-online.target',
            '',
            '[Service]',
            'Type=oneshot',
            'User=root',
            'WorkingDirectory='.$this->escapeDirectivePath($workingDirectory),
            'Environment=ORBIT_HOME='.$this->escapeDirectivePath($orbitHome),
            'ExecStart='.implode(' ', array_map($this->quoteArgument(...), [
                $phpBinary,
                $artisan,
                'orbit:runtime-hibernator',
            ])),
            '',
        ]);
    }

    public function renderTimer(int $sweepSeconds = RuntimeHibernation::DefaultSweepSeconds): string
    {
        return implode("\n", [
            '[Unit]',
            'Description=Orbit runtime hibernator timer',
            '',
            '[Timer]',
            'OnBootSec=2min',
            'OnUnitActiveSec='.$sweepSeconds.'s',
            'Persistent=true',
            'Unit='.$this->serviceName(),
            '',
            '[Install]',
            'WantedBy=timers.target',
            '',
        ]);
    }

    private function quoteArgument(string $argument): string
    {
        return
            '"'
            .str_replace(
                ['\\', '"', '$', '%'],
                ['\\\\', '\\"', '$$', '%%'],
                $argument,
            )
            .'"';
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
