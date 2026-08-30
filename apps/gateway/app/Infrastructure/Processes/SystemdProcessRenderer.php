<?php

declare(strict_types=1);

namespace App\Infrastructure\Processes;

use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Processes\ProcessTarget;
use App\Models\Process;
use InvalidArgumentException;

/** @mago-expect lint:cyclomatic-complexity Unit and certificate validation keep independent failure gates explicit. */
final readonly class SystemdProcessRenderer
{
    public function unitName(Process $process): string
    {
        if ($process->id < 1 || preg_match('/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/D', $process->name) !== 1) {
            throw new InvalidArgumentException('A process needs a persisted ID and safe name before rendering.');
        }

        return "orbit-process-{$process->id}-{$process->name}.service";
    }

    public function unitPath(Process $process): string
    {
        return '/etc/systemd/system/'.$this->unitName($process);
    }

    /** @mago-expect analysis:mixed-assignment Persisted runtime configuration is validated before rendering. */
    public function render(Process $process, ProcessTarget $target, ?ManagedUserAccount $managedAccount = null): string
    {
        $runtimeConfig = $this->runtimeConfig($process);
        $command = $this->stringList($runtimeConfig['command'] ?? null);
        $environmentFile = $runtimeConfig['environment_file'] ?? null;

        if (
            $command === []
            || ! str_starts_with($command[0], '/')
            || ! is_string($environmentFile)
            || $environmentFile === ''
        ) {
            throw new InvalidArgumentException(
                'A systemd process needs an absolute executable, command argv, and an environment file.',
            );
        }

        $certificateProjection = $this->certificateProjection($target, $managedAccount);

        return implode("\n", [
            '[Unit]',
            "Description=Orbit process {$process->name}",
            "X-Orbit-Process-ID={$process->id}",
            'After=network-online.target',
            'Wants=network-online.target',
            '',
            '[Service]',
            'Type=simple',
            "User={$target->user}",
            'WorkingDirectory='.$this->escapeDirectivePath($process->working_directory),
            'Environment=PATH=/usr/local/bin:/opt/orbit/composer/vendor/bin:/usr/bin:/bin',
            'Environment=NODE_USE_SYSTEM_CA=1',
            'EnvironmentFile=-'.$this->escapeDirectivePath($environmentFile),
            ...$certificateProjection['directives'],
            'ExecStart='
                .implode(
                    ' ',
                    array_map(
                        $this->quoteArgument(...),
                        [...$certificateProjection['commandPrefix'], ...$command],
                    ),
                ),
            'Restart='.$this->restartPolicy($process),
            'RestartSec=2',
            '',
            '[Install]',
            'WantedBy=multi-user.target',
            '',
        ]);
    }

    /** @return array{directives: list<string>, commandPrefix: list<string>} */
    private function certificateProjection(ProcessTarget $target, ?ManagedUserAccount $managedAccount): array
    {
        if ($target->certificateScope === null) {
            return ['directives' => [], 'commandPrefix' => []];
        }

        if ($managedAccount === null || $managedAccount->user !== $target->user) {
            throw new InvalidArgumentException('A matching managed account is required for process certificates.');
        }

        $base = rtrim($managedAccount->home, '/')."/.orbit/certificates/{$target->certificateScope}/current/";
        $certificate = $base.'cert.pem';
        $key = $base.'key.pem';

        return [
            'directives' => [
                'Environment=VITE_DEV_SERVER_CERT='.$this->escapeDirectivePath($certificate),
                'Environment=VITE_DEV_SERVER_KEY='.$this->escapeDirectivePath($key),
            ],
            'commandPrefix' => [
                '/usr/bin/env',
                "VITE_DEV_SERVER_CERT={$certificate}",
                "VITE_DEV_SERVER_KEY={$key}",
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function runtimeConfig(Process $process): array
    {
        return $process->runtime_config;
    }

    /**
     * @return list<string>
     *
     * @mago-expect analysis:mixed-assignment Persisted JSON values start at an untyped boundary.
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $arguments = [];

        foreach ($value as $argument) {
            if (! is_string($argument)) {
                return [];
            }

            $arguments[] = $argument;
        }

        return $arguments;
    }

    private function quoteArgument(string $argument): string
    {
        return (
            '"'
            .str_replace(
                ['\\', '"', '$', '%'],
                ['\\\\', '\\"', '$$', '%%'],
                $argument,
            )
            .'"'
        );
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

    private function restartPolicy(Process $process): string
    {
        return match ($process->restart_policy) {
            'never' => 'no',
            'on-failure' => 'on-failure',
            'always', 'unless-stopped' => 'always',
            default => throw new InvalidArgumentException('Unsupported systemd restart policy.'),
        };
    }
}
