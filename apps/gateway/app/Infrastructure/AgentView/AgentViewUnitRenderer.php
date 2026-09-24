<?php

declare(strict_types=1);

namespace App\Infrastructure\AgentView;

final readonly class AgentViewUnitRenderer
{
    public function name(): string
    {
        return 'orbit-agent-view.service';
    }

    public function path(string $unitDirectory = '/etc/systemd/system'): string
    {
        return rtrim($unitDirectory, '/').'/'.$this->name();
    }

    public function render(string $phpBinary, string $artisan, string $orbitHome, string $workingDirectory, string $user): string
    {
        return implode("\n", [
            '[Unit]',
            'Description=Orbit agent view subscriber',
            'After=network-online.target',
            'Wants=network-online.target',
            '',
            '[Service]',
            'Type=simple',
            'User='.$user,
            'WorkingDirectory='.$this->escapeDirectivePath($workingDirectory),
            'Environment=ORBIT_HOME='.$this->escapeDirectivePath($orbitHome),
            'ExecStart='.implode(' ', array_map($this->quoteArgument(...), [
                $phpBinary,
                $artisan,
                'orbit:agent-view',
            ])),
            'Restart=always',
            'RestartSec=2',
            'MemoryMax=256M',
            '',
            '[Install]',
            'WantedBy=multi-user.target',
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
