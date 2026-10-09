<?php

declare(strict_types=1);

namespace App\Infrastructure\Processes;

use App\Domain\AppDev\AgentationEndpoint;
use App\Domain\AppDev\AnnotatorEndpoint;
use App\Domain\AppDev\DevelopmentServerEndpoint;
use App\Domain\AppDev\SsrEndpoint;
use App\Domain\Nodes\LinuxUserName;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Processes\AgentationMcpPreset;
use App\Domain\Processes\AnnotatorPreset;
use App\Domain\Processes\AntigravityWatchPreset;
use App\Domain\Processes\ProcessTarget;
use App\Domain\Processes\VpDevPreset;
use App\Models\Process;
use InvalidArgumentException;
use SensitiveParameter;

final readonly class SystemdProcessRenderer
{
    public static function viteEnvironmentMarker(int $instanceId, ?string $app = null): string
    {
        return "# Orbit Instance {$instanceId}".($app === null ? '' : "\n# Orbit App {$app}");
    }

    public static function viteEnvironmentPath(int $instanceId, ?string $app = null): string
    {
        if ($instanceId < 1) {
            throw new InvalidArgumentException('A Vite environment requires a persisted Instance.');
        }

        return "/etc/orbit/vite/app-instance-{$instanceId}".($app === null ? '' : "-{$app}").'.env';
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
        $user = $runtimeConfig['user'] ?? $target->user;
        if (! is_string($user) || ! LinuxUserName::isValid($user) || (array_key_exists('user', $runtimeConfig) && ($user === 'root' || $target->instance !== null || isset($runtimeConfig['preset'])))) {
            throw new InvalidArgumentException('A Process user must name a non-root account for a Node systemd Process without a preset.');
        }
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
        } elseif ($process->isAnnotator()) {
            $command = AnnotatorPreset::forTarget($target);
        } elseif ($process->isAgentationMcp()) {
            $command = AgentationMcpPreset::command();
        } elseif ($process->isAntigravityWatch()) {
            $command = AntigravityWatchPreset::command();
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
            "User={$user}",
            'WorkingDirectory='.$this->escapeDirectivePath($process->working_directory),
            'Environment=PATH=/usr/local/bin:/opt/orbit/composer/vendor/bin:/usr/bin:/bin',
            'Environment=NODE_USE_SYSTEM_CA=1',
            ...$environmentFileLine,
            ...$this->managedEnvironmentDirectives($process, is_int($target->instance?->ssr_port)),
            ...$environmentProjection['directives'],
            ...($process->isVpDev() ? ['EnvironmentFile='.self::viteEnvironmentPath((int) $target->instance?->id, $target->app !== null && $target->instance?->usesAppViteIdentity($target->app) ? $target->app : null), 'UnsetEnvironment=VITE_DEV_SERVER_CERT VITE_DEV_SERVER_KEY'] : []),
            'ExecStart='
                .implode(
                    ' ',
                    array_map(
                        fn (string $argument): string => ($process->isVpDev() && $argument === '--port=${ORBIT_DEV_SERVER_PORT}')
                            || ($process->isAgentationMcp() && $argument === '--port=${'.AgentationEndpoint::PORT_KEY.'}')
                            ? '"'.$argument.'"'
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

    /**
     * @return list<string>
     */
    private function managedEnvironmentDirectives(#[SensitiveParameter] Process $process, bool $ssr): array
    {
        if (! array_key_exists('environment', $process->runtime_config)) {
            return [];
        }

        $directives = [];

        foreach ($this->stringMap($process->runtime_config['environment']) as $name => $value) {
            if ($this->isReservedEnvironmentName($name) || ($ssr && in_array($name, [SsrEndpoint::PORT_KEY, SsrEndpoint::URL_KEY], true))) {
                continue;
            }

            $directives[] = 'Environment='.$name.'='.$this->escapeDirectivePath($value);
        }

        return $directives;
    }

    private function isReservedEnvironmentName(string $name): bool
    {
        return in_array($name, [
            'PATH',
            'NODE_USE_SYSTEM_CA',
            'VITE_DEV_SERVER_CERT',
            'VITE_DEV_SERVER_KEY',
            'ORBIT_DEV_SERVER_ORIGIN',
            'ORBIT_DEV_SERVER_HOST',
            'ORBIT_DEV_SERVER_PATH',
            'ORBIT_DEV_SERVER_PORT',
            AnnotatorEndpoint::URL_KEY,
            AnnotatorEndpoint::PORT_KEY,
            AgentationEndpoint::URL_KEY,
            AgentationEndpoint::PORT_KEY,
        ], true);
    }

    /**
     * @return array<string, string>
     */
    private function stringMap(#[SensitiveParameter] mixed $value): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException('A systemd environment must be a string map.');
        }

        $items = [];

        foreach ($value as $name => $item) {
            if (! is_string($name) || ! is_string($item)) {
                throw new InvalidArgumentException('Systemd environment values must be strings.');
            }

            if (
                preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $name) !== 1
                || str_contains($item, "\0")
                || str_contains($item, "\r")
                || str_contains($item, "\n")
            ) {
                throw new InvalidArgumentException('A systemd environment needs safe names and single-line values.');
            }

            $items[$name] = $item;
        }

        ksort($items);

        return $items;
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
            $commandValues[] = "ORBIT_DEV_SERVER_ORIGIN={$origin}";
            $commandValues[] = "ORBIT_DEV_SERVER_HOST={$target->routeDomain}";
            $commandValues[] = 'ORBIT_DEV_SERVER_PATH='.DevelopmentServerEndpoint::PATH;
            $port = $target->port('vite_port');

            if (is_int($port)) {
                $directives[] = 'Environment=ORBIT_DEV_SERVER_PORT='.(string) $port;
                $commandValues[] = 'ORBIT_DEV_SERVER_PORT='.(string) $port;
            }

            if (is_int($target->port('annotator_port'))) {
                $annotatorOrigin = AnnotatorEndpoint::queue($target->routeDomain);
                $directives[] = 'Environment='.AnnotatorEndpoint::URL_KEY.'='.$this->escapeDirectivePath($annotatorOrigin);
                $commandValues[] = AnnotatorEndpoint::URL_KEY.'='.$annotatorOrigin;
            }

            if (is_int($target->port('agentation_port'))) {
                $agentationOrigin = AgentationEndpoint::origin($target->routeDomain);
                $directives[] = 'Environment='.AgentationEndpoint::URL_KEY.'='.$this->escapeDirectivePath($agentationOrigin);
                $directives[] = 'Environment='.AgentationEndpoint::PORT_KEY.'='.(string) $target->port('agentation_port');
                $commandValues[] = AgentationEndpoint::URL_KEY.'='.$agentationOrigin;
                $commandValues[] = AgentationEndpoint::PORT_KEY.'='.(string) $target->port('agentation_port');
            }
        }

        if (is_int($target->instance?->ssr_port)) {
            foreach (SsrEndpoint::environment($target->instance->ssr_port) as $name => $value) {
                $directives[] = "Environment={$name}={$value}";
                $commandValues[] = "{$name}={$value}";
            }
        }

        if (is_int($target->port('annotator_port'))) {
            $directives[] = 'Environment='.AnnotatorEndpoint::PORT_KEY.'='.(string) $target->port('annotator_port');
            $commandValues[] = AnnotatorEndpoint::PORT_KEY.'='.(string) $target->port('annotator_port');
            if ($target->routeDomain === null) {
                $directives[] = 'UnsetEnvironment='.AnnotatorEndpoint::URL_KEY;
            }
        } elseif ($target->instance !== null) {
            $directives[] = 'UnsetEnvironment='.AnnotatorEndpoint::URL_KEY.' '.AnnotatorEndpoint::PORT_KEY;
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
