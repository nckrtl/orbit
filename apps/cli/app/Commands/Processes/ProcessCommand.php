<?php

declare(strict_types=1);

namespace App\Commands\Processes;

use App\Commands\GatewayCommand;

/**
 * @mago-expect lint:cyclomatic-complexity Process response redaction covers recursive mixed SDK values.
 * @mago-expect lint:kan-defect Each branch applies one bounded redaction rule.
 */
abstract class ProcessCommand extends GatewayCommand
{
    /** @param array<string, mixed> $payload
     *  @return array<string, mixed>
     *
     * @mago-expect lint:inline-variable-return The local assertion preserves the DTO's string-keyed shape.
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
     *  @return list<array<string, mixed>>
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
     * @mago-expect analysis:mixed-assignment Process DTO runtime configuration has recursive mixed values.
     *
     * @param array<array-key, mixed> $values
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
     * @mago-expect analysis:mixed-assignment Corrupted SDK command elements are sanitized by runtime type below.
     *
     * @param list<mixed> $arguments
     *  @return list<mixed>
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
        return (
            preg_match(
                '/\A'.$this->sensitiveNamePattern().'\z/iD',
                $key,
            ) === 1
        );
    }

    private function sensitiveNamePattern(): string
    {
        return (
            '[A-Z0-9_.-]*(?:APP[_-]?KEY|APPLICATION[_-]?KEY|API[_-]?KEY|ACCESS[_-]?TOKEN|'
            .'REFRESH[_-]?TOKEN|OPERATION[_-]?TOKEN|EXECUTOR[_-]?SECRET|PRIVATE[_-]?KEY|'
            .'PRE[_-]?SHARED[_-]?KEY|PASSWORD[_-]?HASH|PASSWORD|PASSWD|PWD|SECRET|TOKEN|'
            .'BEARER[_-]?TOKEN|CREDENTIAL|COOKIE)[A-Z0-9_.-]*'
        );
    }

    /** @return array{type: string, id: int}|null */
    protected function target(): ?array
    {
        $instance = $this->option('instance');
        $workspace = $this->option('workspace');
        $instanceSelected = is_string($instance) && $instance !== '';
        $workspaceSelected = is_string($workspace) && $workspace !== '';

        if ($instanceSelected === $workspaceSelected) {
            $this->renderGatewayFailure(
                'process.target_invalid',
                'Select exactly one instance or workspace target.',
            );

            return null;
        }

        if ($instanceSelected) {
            return $this->validatedTarget('instance', $instance);
        }

        if ($workspaceSelected) {
            return $this->validatedTarget('workspace', $workspace);
        }

        return null;
    }

    /** @return array{type: string, id: int}|null */
    private function validatedTarget(string $type, string $value): ?array
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if (! is_int($id)) {
            $this->renderGatewayFailure(
                'process.target_id_invalid',
                'Process target ID must be a positive integer.',
            );

            return null;
        }

        return [
            'type' => $type,
            'id' => $id,
        ];
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
}
