<?php

declare(strict_types=1);

namespace App\Commands\Processes;

use App\Commands\GatewayCommand;

abstract class ProcessCommand extends GatewayCommand
{
    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    protected function sanitizedProcessPayload(array $payload): array
    {
        /** @var mixed $runtimeConfig */
        $runtimeConfig = $payload['runtime_config'] ?? null;

        if (! is_array($runtimeConfig)) {
            return $payload;
        }

        unset($runtimeConfig['environment']);
        $payload['runtime_config'] = $runtimeConfig;

        /** @var array<string, mixed> $sanitized */
        $sanitized = $this->sanitizedProcessValues($payload);

        return $sanitized;
    }

    /** @param list<array<string, mixed>> $payloads
     * @return list<array<string, mixed>>
     */
    protected function sanitizedProcessCollection(array $payloads): array
    {
        return array_map(
            $this->sanitizedProcessPayload(...),
            $payloads,
        );
    }

    protected function sanitizedLogs(string $logs): string
    {
        $sensitiveName = $this->sensitiveNamePattern();
        $redacted = str_ireplace(
            search: '[REDACTED]',
            replace: '[redacted]',
            subject: $logs,
        );
        $patterns = [
            '/-----BEGIN [A-Z0-9 ]+-----[\s\S]*?-----END [A-Z0-9 ]+-----/' => '[redacted]',
            '/((?:^|[,{]\s*)["\']?'
                .$sensitiveName
                .'["\']?\s*(?:=|:)\s*)(?:"[^"\r\n]*"|\'[^\'\r\n]*\'|[^,\s}\r\n]+)/im' => '$1[redacted]',
            '/\b('.$sensitiveName.')\s*=\s*(?:"[^"\r\n]*"|\'[^\'\r\n]*\'|[^\s&,}\r\n]+)/i' => '$1=[redacted]',
            '/\b((?:Proxy-)?Authorization)\s*:\s*[^\r\n]*/i' => '$1: [redacted]',
            '/\b(Bearer)\s+(?:"[^"]*"|\'[^\']*\'|[A-Za-z0-9][A-Za-z0-9._\-+\/=]{7,})/i' => '$1 [redacted]',
            '/(\b[a-z][a-z0-9+.-]*:\/\/)[^@\s\/]+@/i' => '$1[redacted]@',
            '/([?&](?:'.$sensitiveName.'|passwd|credential|cookie)=)[^&\s]+/i' => '$1[redacted]',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $result = preg_replace(pattern: $pattern, replacement: $replacement, subject: $redacted);

            if (is_string($result)) {
                $redacted = $result;
            }
        }

        return $redacted;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function sanitizedProcessValues(array $values): array
    {
        $sanitized = [];

        foreach ($values as $key => $value) {
            if ($key === 'command' && is_array($value) && array_is_list($value)) {
                $sanitized[$key] = $this->sanitizedCommand($value);

                continue;
            }

            if (is_string($key) && $this->isSensitiveRuntimeKey($key)) {
                $sanitized[$key] = '[redacted]';

                continue;
            }

            $sanitized[$key] = match (true) {
                is_array($value) => $this->sanitizedProcessValues($value),
                is_string($value) => $this->sanitizedLogs($value),
                default => $value,
            };
        }

        return $sanitized;
    }

    /**
     * @param  list<mixed>  $arguments
     * @return list<mixed>
     */
    private function sanitizedCommand(array $arguments): array
    {
        $sanitized = [];
        $redactNext = false;

        foreach ($arguments as $argument) {
            if ($redactNext) {
                $sanitized[] = '[redacted]';
                $redactNext = false;

                continue;
            }

            if (! is_string($argument)) {
                $sanitized[] = is_array($argument)
                    ? $this->sanitizedProcessValues($argument)
                    : $argument;

                continue;
            }

            $redactNext =
                preg_match(
                    '/\A'.$this->sensitiveNamePattern().'\z/iD',
                    ltrim(string: $argument, characters: '-'),
                ) === 1;
            $sanitized[] = $this->sanitizedLogs($argument);
        }

        return $sanitized;
    }

    private function isSensitiveRuntimeKey(string $key): bool
    {
        return
            preg_match(
                '/\A'.$this->sensitiveNamePattern().'\z/iD',
                $key,
            ) === 1;
    }

    private function sensitiveNamePattern(): string
    {
        return
            '[A-Z0-9_.-]*(?:APP[_-]?KEY|APPLICATION[_-]?KEY|API[_-]?KEY|ACCESS[_-]?TOKEN|'
            .'REFRESH[_-]?TOKEN|OPERATION[_-]?TOKEN|EXECUTOR[_-]?SECRET|PRIVATE[_-]?KEY|'
            .'PRE[_-]?SHARED[_-]?KEY|PASSWORD[_-]?HASH|PASSWORD|PASSWD|PWD|SECRET|TOKEN|'
            .'BEARER[_-]?TOKEN|CREDENTIAL|COOKIE)[A-Z0-9_.-]*';
    }

    /** @return list<string> */
    protected function stringListOption(string $name): array
    {
        $values = $this->option($name);

        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter($values, is_string(...)));
    }

    /** @return array<string, string>|null */
    protected function environment(): ?array
    {
        $environment = [];
        $values = $this->stringListOption('environment');

        if (count($values) > 100) {
            $this->renderInvalidEnvironment();

            return null;
        }

        foreach ($values as $value) {
            [$name, $item] = array_pad(explode('=', $value, limit: 2), length: 2, value: null);

            if (
                ! is_string($name)
                || ! is_string($item)
                || strlen($item) > 4096
                || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $name) !== 1
                || preg_match('/[\x00\r\n]/', $value) === 1
            ) {
                $this->renderInvalidEnvironment();

                return null;
            }

            $environment[$name] = $item;
        }

        return $environment;
    }

    /** @return list<array{source: string, target: string, read_only: bool}>|null */
    protected function volumes(): ?array
    {
        $volumes = [];
        $values = $this->stringListOption('volume');

        if (count($values) > 100) {
            $this->renderInvalidVolume();

            return null;
        }

        foreach ($values as $value) {
            $segments = explode(':', $value);
            $readOnly = ($segments[2] ?? null) === 'ro';
            $source = $segments[0];
            $target = $segments[1] ?? '';

            if (
                preg_match('/[\x00\r\n]/', $value) === 1
                || ! in_array(count($segments), [2, 3], strict: true)
                || strlen($source) > 4096
                || strlen($target) > 4096
                || ($segments[2] ?? null) !== null
                && ! $readOnly
            ) {
                $this->renderInvalidVolume();

                return null;
            }

            $volumes[] = [
                'source' => $segments[0],
                'target' => $segments[1],
                'read_only' => $readOnly,
            ];
        }

        return $volumes;
    }

    /** @return list<string>|null */
    protected function ports(): ?array
    {
        $ports = $this->stringListOption('port');

        if (
            count($ports) > 100
            || array_any(
                $ports,
                static fn (string $port): bool => strlen($port) > 4096
                || preg_match('/[\x00-\x1F\x7F]/', $port) === 1,
            )
        ) {
            $this->renderInvalidPort();

            return null;
        }

        return $ports;
    }

    private function renderInvalidEnvironment(): void
    {
        $this->renderGatewayFailure(
            'process.environment_invalid',
            'Invalid environment value. Use NAME=VALUE.',
        );
    }

    private function renderInvalidPort(): void
    {
        $this->renderGatewayFailure(
            'process.port_invalid',
            'Process port value is invalid.',
        );
    }

    private function renderInvalidVolume(): void
    {
        $this->renderGatewayFailure(
            'process.volume_invalid',
            'Invalid volume. Use SOURCE:TARGET[:ro].',
        );
    }
}
