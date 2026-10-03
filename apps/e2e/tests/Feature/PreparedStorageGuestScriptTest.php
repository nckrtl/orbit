<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('cleans prepared storage in order and stops when a cleanup command fails', function (string $mode, string $failure, int $exitCode, array $commands): void {
    $root = temporaryPath('orbit-storage-test-');
    mkdir($root.'/bin', 0700, true);
    foreach (['docker', 'apt-get', 'sync', 'fstrim'] as $command) {
        file_put_contents($root.'/bin/'.$command, <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
command=${0##*/}
printf '%s %s\n' "$command" "$*" >>"$STORAGE_COMMANDS"
[[ "$command" != "$STORAGE_FAILURE" ]] || exit 73
BASH);
        chmod($root.'/bin/'.$command, 0700);
    }
    $process = new Process(['bash', guestScriptPath('prepare-node.sh'), 'compact-storage', $mode], env: [
        'PATH' => $root.'/bin:'.getenv('PATH'),
        'STORAGE_COMMANDS' => $root.'/commands',
        'STORAGE_FAILURE' => $failure,
    ]);

    expect($process->run())->toBe($exitCode, $process->getErrorOutput());
    expect(file_exists($root.'/commands') ? file($root.'/commands', FILE_IGNORE_NEW_LINES) : [])->toBe($commands);
})->with([
    'development keeps warm images' => ['keep-images', '', 0, ['apt-get clean', 'sync -f /', 'fstrim --quiet-unsupported /']],
    'other Nodes prune unused images' => ['prune-images', '', 0, ['docker image prune --all --force', 'apt-get clean', 'sync -f /', 'fstrim --quiet-unsupported /']],
    'Docker failure' => ['prune-images', 'docker', 73, ['docker image prune --all --force']],
    'APT failure' => ['prune-images', 'apt-get', 73, ['docker image prune --all --force', 'apt-get clean']],
    'flush failure' => ['keep-images', 'sync', 73, ['apt-get clean', 'sync -f /']],
    'discard failure' => ['keep-images', 'fstrim', 73, ['apt-get clean', 'sync -f /', 'fstrim --quiet-unsupported /']],
    'invalid policy' => ['unknown', '', 64, []],
]);
