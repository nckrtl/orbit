<?php

declare(strict_types=1);

namespace App\Infrastructure\Processes;

use App\Domain\AppDev\DevelopmentServerEndpoint;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Processes\ProcessTarget;
use App\Domain\Processes\VpDevPreset;
use App\Models\Process;
use InvalidArgumentException;

final readonly class SystemdProcessRenderer
{
    public static function viteEnvironmentPath(int $instanceId): string
    {
        if ($instanceId < 1) {
            throw new InvalidArgumentException('A Vite environment requires a persisted AppInstance.');
        }

        return "/etc/orbit/vite/app-instance-{$instanceId}.env";
    }

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

    public function render(Process $process, ProcessTarget $target, ?ManagedUserAccount $managedAccount = null): string
    {
        $runtimeConfig = $this->runtimeConfig($process);
        $command = $this->stringList($runtimeConfig['command'] ?? null);
        $environmentFile = $process->isVpDev() ? $target->environmentFile : ($runtimeConfig['environment_file'] ?? null);

        if (
            $command === []
            || ! str_starts_with($command[0], '/')
            || ($environmentFile !== null && ! is_string($environmentFile))
        ) {
            throw new InvalidArgumentException(
                'A systemd process needs an absolute executable and command argv.',
            );
        }

        $environmentProjection = $this->environmentProjection($target, $managedAccount, ! $process->isVpDev());
        if ($process->isVpDev()) {
            $command = VpDevPreset::command();
            $environmentProjection['commandPrefix'] = array_values(array_filter($environmentProjection['commandPrefix'], static fn (string $value): bool => ! str_starts_with($value, 'ORBIT_DEV_SERVER_PORT=')));
        }
        $environmentFileLine = is_string($environmentFile) && $environmentFile !== ''
            ? ['EnvironmentFile=-'.$this->escapeDirectivePath($environmentFile)]
            : [];

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
            ...$environmentFileLine,
            ...$environmentProjection['directives'],
            ...($process->isVpDev() ? ['EnvironmentFile='.self::viteEnvironmentPath((int) $target->appInstance?->id), 'UnsetEnvironment=VITE_DEV_SERVER_CERT VITE_DEV_SERVER_KEY'] : []),
            'ExecStart='
                .implode(
                    ' ',
                    array_map(
                        fn (string $argument): string => $process->isVpDev() && $argument === '--port=${ORBIT_DEV_SERVER_PORT}'
                            ? '"--port=${ORBIT_DEV_SERVER_PORT}"'
                            : $this->quoteArgument($argument),
                        [...$environmentProjection['commandPrefix'], ...$command],
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
    private function environmentProjection(ProcessTarget $target, ?ManagedUserAccount $managedAccount, bool $certificates): array
    {
        $directives = [];
        $commandValues = [];

        if (is_string($target->routeDomain) && $target->routeDomain !== '') {
            $origin = DevelopmentServerEndpoint::origin($target->routeDomain);
            $directives[] = 'Environment=ORBIT_DEV_SERVER_ORIGIN='.$this->escapeDirectivePath($origin);
            $directives[] = 'Environment=ORBIT_DEV_SERVER_HOST='.$this->escapeDirectivePath($target->routeDomain);
            $directives[] = 'Environment=ORBIT_DEV_SERVER_PATH='.DevelopmentServerEndpoint::PATH;
            $directives[] = 'Environment=ORBIT_DEV_SERVER_PORT='.(string) ($target->appInstance->vite_port ?? DevelopmentServerEndpoint::PORT);
            $commandValues[] = "ORBIT_DEV_SERVER_ORIGIN={$origin}";
            $commandValues[] = "ORBIT_DEV_SERVER_HOST={$target->routeDomain}";
            $commandValues[] = 'ORBIT_DEV_SERVER_PATH='.DevelopmentServerEndpoint::PATH;
            $commandValues[] = 'ORBIT_DEV_SERVER_PORT='.(string) ($target->appInstance->vite_port ?? DevelopmentServerEndpoint::PORT);
        }

        if ($certificates && $target->certificateScope !== null) {
            if ($managedAccount === null || $managedAccount->user !== $target->user) {
                throw new InvalidArgumentException('A matching managed account is required for process certificates.');
            }

            $base = rtrim($managedAccount->home, '/')."/.orbit/certificates/{$target->certificateScope}/current/";
            $certificate = $base.'cert.pem';
            $key = $base.'key.pem';
            $directives[] = 'Environment=VITE_DEV_SERVER_CERT='.$this->escapeDirectivePath($certificate);
            $directives[] = 'Environment=VITE_DEV_SERVER_KEY='.$this->escapeDirectivePath($key);
            $commandValues[] = "VITE_DEV_SERVER_CERT={$certificate}";
            $commandValues[] = "VITE_DEV_SERVER_KEY={$key}";
        }

        return [
            'directives' => $directives,
            'commandPrefix' => $commandValues === [] ? [] : ['/usr/bin/env', ...$commandValues],
        ];
    }

    /** @return array<string, mixed> */
    private function runtimeConfig(Process $process): array
    {
        return $process->runtime_config;
    }

    /**
     * @return list<string>
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
