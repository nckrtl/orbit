<?php

declare(strict_types=1);

/** @return list<array{file:string,line:int,command:string}> */
function unsafeProofPipelines(): array
{
    $repositoryRoot = dirname(__DIR__, 5);
    $unsafe = [];
    $files = glob($repositoryRoot.'/.loop/proof/*.sh') ?: [];

    foreach ($files as $file) {
        $contents = file_get_contents($file);
        assert(is_string($contents));
        $logicalContents = preg_replace('/\\\\\R[ \t]*/', ' ', $contents);
        assert(is_string($logicalContents));

        foreach (preg_split('/\R/', $logicalContents) ?: [] as $lineNumber => $line) {
            if (str_starts_with(ltrim($line), '#')) {
                continue;
            }

            if (
                preg_match(
                    '/\|\s*(?:grep\s+-[A-Za-z]*q[A-Za-z]*\b|head(?:\s|$)|awk\s+.*(?:\bexit\b|NR\s*==\s*1))/',
                    $line,
                ) !== 1
            ) {
                continue;
            }

            $unsafe[] = [
                'file' => str_replace($repositoryRoot.'/', '', $file),
                'line' => $lineNumber + 1,
                'command' => trim($line),
            ];
        }
    }

    return $unsafe;
}

it('keeps early-exit proof pipeline producers truthful under pipefail', function (): void {
    $allowed = [];
    $unexpected = [];

    foreach (unsafeProofPipelines() as $pipeline) {
        $normalized = preg_replace('/\s+/', ' ', $pipeline['command']);
        assert(is_string($normalized));
        $normalizedAllowed = array_map(
            static fn (string $command): string => preg_replace('/\s+/', ' ', $command) ?? $command,
            $allowed[$pipeline['file']] ?? [],
        );
        if (in_array($normalized, $normalizedAllowed, true)) {
            continue;
        }

        $unexpected[] = "{$pipeline['file']}:{$pipeline['line']} {$pipeline['command']}";
    }

    expect($unexpected)->toBe([]);
});

it('keeps the extended runtime fixture fail-closed at its local and remote shell boundaries', function (): void {
    $source = file_get_contents(dirname(__DIR__, 5).'/.loop/proof/extended-runtime-connectivity.sh');
    assert(is_string($source));

    expect($source)
        ->toContain(
            'set -euo pipefail',
            '[[ $# -eq 3 ]]',
            '[[ -r "$database" ]]',
            'ping -c 1 -W 5 -- "$extra_address"',
            '-o BatchMode=yes',
            '-o ConnectTimeout=5',
            '-o StrictHostKeyChecking=yes',
            '-o UserKnownHostsFile=/home/orbit/.orbit/ssh/known_hosts',
            '"orbit@$extra_address"',
            '"set -euo pipefail; php -r',
            'systemctl is-active php8.5-fpm',
            'systemctl is-active caddy',
            'sudo caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile',
        )
        ->not->toContain('StrictHostKeyChecking=no', '|| true');
});

it('refuses missing or altered extended-runtime action arguments before inspection', function (array $arguments): void {
    $script = dirname(__DIR__, 5).'/.loop/proof/extended-runtime-connectivity.sh';
    $result = new \Illuminate\Process\Factory()->run(['bash', $script, ...$arguments]);

    expect($result->successful())->toBeFalse();
})->with([
    'missing arguments' => [[]],
    'wrong original Node' => [['app-dev', 'app-prod-2', '10.44.0.4']],
    'wrong extra Node' => [['app-prod', 'extra', '10.44.0.4']],
    'wrong extra address' => [['app-prod', 'app-prod-2', '10.44.0.5']],
]);
