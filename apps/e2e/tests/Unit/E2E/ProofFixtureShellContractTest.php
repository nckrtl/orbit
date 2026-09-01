<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/** @return list<array{file:string,line:int,command:string}> */
function unsafeProofPipelines(): array
{
    $repositoryRoot = dirname(__DIR__, 5);
    $proofRoot = $repositoryRoot . '/proofs';
    $unsafe = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($proofRoot));

    foreach ($files as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'sh') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());
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
                'file' => str_replace($repositoryRoot . '/', '', $file->getPathname()),
                'line' => $lineNumber + 1,
                'command' => trim($line),
            ];
        }
    }

    return $unsafe;
}

it('keeps early-exit proof pipeline producers truthful under pipefail', function () {
    $allowed = [
        'proofs/NCK-116/lib.sh' => [
            'number=$(grep "# $1\\$" <<<"$(firewall_status_text)" | sed -E \'s/^ *\\[ *([0-9]+)\\].*/\\1/\' | head -1 || true)',
        ],
        'proofs/NCK-116/refuses-a-shifted-rule-number.sh' => [
            'grep "# $1\\$" <<<"$(firewall_status_text)" | sed -E \'s/^ *\\[ *([0-9]+)\\].*/\\1/\' | head -1 || true',
            'planned_number=$(sudo /usr/sbin/ufw status numbered 2>/dev/null | grep "# $EXPORTER_RULE_COMMENT\\$" | sed -E \'s/^ *\\[ *([0-9]+)\\].*/\\1/\' | head -1 || true)',
        ],
    ];
    $unexpected = [];

    foreach (unsafeProofPipelines() as $pipeline) {
        $normalized = preg_replace('/\s+/', ' ', $pipeline['command']);
        assert(is_string($normalized));
        $normalizedAllowed = array_map(
            static fn(string $command): string => preg_replace('/\s+/', ' ', $command) ?? $command,
            $allowed[$pipeline['file']] ?? [],
        );
        if (in_array($normalized, $normalizedAllowed, true)) {
            continue;
        }

        $unexpected[] = "{$pipeline['file']}:{$pipeline['line']} {$pipeline['command']}";
    }

    expect($unexpected)->toBe([]);
});

it('records the first owned firewall delta when no earlier rule shape exists', function () {
    $fixture = dirname(__DIR__, 5) . '/proofs/NCK-116/lib.sh';
    $script = <<<'BASH'
        source "$1"

        sudo() {
          case "$1" in
            /usr/sbin/ufw)
              printf 'Status: active\n\n[ 1] BASELINE\n[ 2] DECOY\n'
              ;;
            cat)
              printf 'BASELINE\n'
              ;;
            install)
              return 0
              ;;
            test)
              return 1
              ;;
            *)
              return 1
              ;;
          esac
        }

        orb7_record_ufw_delta escape-metrics-node decoy
        BASH;

    $result = new Process(['bash', '-c', $script, 'orb7-firewall-delta', $fixture]);
    $result->run();

    expect($result->isSuccessful())->toBeTrue($result->getErrorOutput());
});
