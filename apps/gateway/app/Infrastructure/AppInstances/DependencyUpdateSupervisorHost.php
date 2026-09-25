<?php

declare(strict_types=1);

namespace App\Infrastructure\AppInstances;

use LogicException;

/**
 * Selects the host commands that a dependency update supervisor program uses.
 *
 * Nodes run Ubuntu, so the programs name `/usr/bin/setsid`, `/usr/bin/bash`, and `/usr/bin/composer` and read
 * an owner's process state from `/proc`. Node leaves the program unchanged, and every Node operation uses it.
 * Portable finds those commands on PATH and reads the process state with `ps`. It lets the supervisor's
 * process-group, deadline, and cancellation logic run on a development host that has no `/proc`, such as
 * macOS. Both variants are fixed here; callers cannot supply shell text.
 */
enum DependencyUpdateSupervisorHost
{
    case Node;
    case Portable;

    /** @var array<string, string> Node program text and its Portable replacement, applied in this order. */
    private const array PortableReplacements = [
        <<<'BASH'
                if [ ! -d "/proc/$owner" ]; then
                    return 0
                fi
                state=$(awk '{print $3}' "/proc/$owner/stat" 2>/dev/null || true)
            BASH => <<<'BASH'
                state=$(ps -o state= -p "$owner" 2>/dev/null | cut -c1 || true)
            BASH,
        <<<'BASH'
                if [ ! -d "/proc/$owner" ]; then
                    return 0
                fi
                state=$(awk "{print \$3}" "/proc/$owner/stat" 2>/dev/null || true)
            BASH => <<<'BASH'
                state=$(ps -o state= -p "$owner" 2>/dev/null | cut -c1 || true)
            BASH,
        '/usr/bin/setsid ' => 'setsid ',
        '/usr/bin/bash ' => 'bash ',
        '/usr/bin/composer ' => 'composer ',
    ];

    public function program(string $nodeProgram): string
    {
        if ($this === self::Node) {
            return $nodeProgram;
        }

        foreach (self::PortableReplacements as $node => $portable) {
            $nodeProgram = str_replace($node, $portable, $nodeProgram);
        }

        if (str_contains($nodeProgram, '/proc/')) {
            throw new LogicException('The supervisor program reads /proc in a way the portable host does not replace.');
        }

        return $nodeProgram;
    }

    /** @return list<string> The argv prefix that starts a supervisor program in its own session. */
    public function launcher(): array
    {
        return match ($this) {
            self::Node => ['/usr/bin/setsid', '--wait', '/usr/bin/bash', '-eu', '-c'],
            self::Portable => ['setsid', '--wait', 'bash', '-eu', '-c'],
        };
    }
}
