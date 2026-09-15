<?php

declare(strict_types=1);

namespace App\Support\Console;

/** Test-only system-call substitutions; the renderer child never loads this file. */
function proc_open(array|string $command, array $descriptorSpec, ?array &$pipes, ?string $cwd = null, ?array $env = null, ?array $options = null): mixed
{
    if (! is_array($command) || ! str_ends_with($command[1] ?? '', '/Renderers/animate.php')) {
        return \proc_open($command, $descriptorSpec, $pipes, $cwd, $env, $options ?? []);
    }

    if (getenv('ORBIT_UX_FAULT') === 'startup') {
        return false;
    }

    $process = \proc_open($command, $descriptorSpec, $pipes, $cwd, $env, $options ?? []);

    if (is_resource($process)) {
        $status = \proc_get_status($process);
        $GLOBALS['orbit_ux_renderer_pid'] = $status['pid'];
        $GLOBALS['orbit_ux_renderer_input'] = $pipes[0];
        $trace = getenv('ORBIT_UX_TRACE');

        if (is_string($trace) && $trace !== '') {
            file_put_contents($trace, json_encode(['event' => 'renderer', 'pid' => $status['pid'], 'parent' => getmypid()], JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
        }
    }

    return $process;
}

function fwrite(mixed $stream, string $data, ?int $length = null): int|false
{
    if (getenv('ORBIT_UX_FAULT') === 'ipc' && $stream === ($GLOBALS['orbit_ux_renderer_input'] ?? null)) {
        return false;
    }

    return $length === null ? \fwrite($stream, $data) : \fwrite($stream, $data, $length);
}
