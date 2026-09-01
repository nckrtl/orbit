<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/** @return list<array{file:string,line:int,command:string}> */
function unsafeProofPipelines(): array
{
    $repositoryRoot = dirname(__DIR__, 5);
    $proofRoot = $repositoryRoot.'/proofs';
    $unsafe = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($proofRoot));

    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'sh') {
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
                'file' => str_replace($repositoryRoot.'/', '', $file->getPathname()),
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

it('records the first owned firewall delta when no earlier rule shape exists', function () {
    $fixture = dirname(__DIR__, 5).'/proofs/NCK-116/lib.sh';
    $script = <<<'BASH'
        source "$1"

        sudo() {
          case "$1" in
            /usr/sbin/ufw)
              printf 'Status: active\n\n[ 1] BASELINE\n[ 2] DECOY\n'
              ;;
            cat)
              if [[ "$2" == */ufw.before ]]; then
                printf 'BASELINE\n'
              fi
              ;;
            install)
              return 0
              ;;
            tee)
              cat >/dev/null
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

it('records consecutive owned firewall deltas without globbing the root record', function () {
    $fixture = dirname(__DIR__, 5).'/proofs/NCK-116/lib.sh';
    $script = <<<'BASH'
        source "$1"

        manifest=$(mktemp)
        trap 'rm -f -- "$manifest"' EXIT
        ufw_state=first

        sudo() {
          case "$1" in
            /usr/sbin/ufw)
              printf 'Status: active\n\n[ 1] BASELINE\n[ 2] FIRST\n'
              [[ "$ufw_state" == second ]] && printf '[ 3] SECOND\n'
              ;;
            cat)
              if [[ "$2" == */ufw.before ]]; then
                printf 'BASELINE\n'
              elif [[ "$2" == */rules.tsv ]]; then
                cat "$manifest"
              fi
              ;;
            install)
              return 0
              ;;
            tee)
              cat >>"$manifest"
              ;;
            test)
              return 1
              ;;
            *)
              return 1
              ;;
          esac
        }

        orb7_record_ufw_delta escape-without-wireguard-address first
        ufw_state=second
        orb7_record_ufw_delta escape-without-wireguard-address second
        [[ "$(cat "$manifest")" == $'FIRST\nSECOND' ]]
        BASH;

    $result = new Process(['bash', '-c', $script, 'orb7-firewall-deltas', $fixture]);
    $result->run();

    expect($result->isSuccessful())->toBeTrue($result->getErrorOutput());
});

it('restores firewall deltas from the root-owned shape manifest', function () {
    $fixture = dirname(__DIR__, 5).'/proofs/NCK-116/lib.sh';
    $script = <<<'BASH'
        source "$1"

        manifest=$(mktemp)
        deleted=$(mktemp)
        trap 'rm -f -- "$manifest" "$deleted"' EXIT
        printf 'OWNED\n' >"$manifest"

        sudo() {
          case "$1" in
            /usr/sbin/ufw)
              if [[ "$2" == status ]]; then
                printf 'Status: active\n\n[ 1] BASELINE\n[ 2] OWNED\n'
              elif [[ "$2" == --force && "$3" == delete ]]; then
                printf '%s\n' "$4" >"$deleted"
              fi
              ;;
            cat)
              if [[ "$2" == */rules.tsv ]]; then
                cat "$manifest"
              fi
              ;;
            mkdir)
              return 0
              ;;
            rm)
              return 0
              ;;
            tee)
              cat >/dev/null
              ;;
            test)
              if [[ "$2" == -e && "$3" == "$ORB7_CLEANUP_ROOT/cleanup-case" ]]; then
                return 0
              fi
              return 1
              ;;
            *)
              return 1
              ;;
          esac
        }

        orb7_restore_owned cleanup-case
        [[ "$(cat "$deleted")" == 2 ]]
        BASH;

    $result = new Process(['bash', '-c', $script, 'orb7-firewall-cleanup', $fixture]);
    $result->run();

    expect($result->isSuccessful())->toBeTrue($result->getErrorOutput());
});

it('maps every ORB-7 acceptance criterion to an issue-owned proof action', function () {
    $repositoryRoot = dirname(__DIR__, 5);
    $plan = json_decode(
        (string) file_get_contents($repositoryRoot.'/proofs/ORB-7.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect(array_column($plan['acceptance'], 'id'))
        ->toBe([
            'pipefail-assertions-remain-truthful',
            'fixtures-restore-on-exit-int-term',
            'terminated-firewall-fixture-restores-owned-state',
            'timeouts-send-term-then-force-kill',
            'historical-metrics-proof-remains-valid',
        ])
        ->and(array_column($plan['acceptance'], 'argv'))
        ->toBe([
            ['bash', '/var/lib/orbit-e2e/proof/pipefail-assertions.sh'],
            ['bash', '/var/lib/orbit-e2e/proof/fixture-cleanup-matrix.sh'],
            ['bash', '/var/lib/orbit-e2e/proof/firewall-timeout-restoration.sh'],
            ['bash', '/var/lib/orbit-e2e/proof/timeout-boundary.sh'],
            ['bash', '/var/lib/orbit-e2e/proof/historical-metrics-proof.sh'],
        ])
        ->and($plan['mutates'])
        ->toBeTrue();
});

it('keeps the NCK-104 ORB-7 cleanup active while restoring the home ACL', function () {
    $repositoryRoot = dirname(__DIR__, 5);
    $fixture = (string) file_get_contents($repositoryRoot.'/proofs/NCK-104/removal-restoration.sh');
    $library = (string) file_get_contents($repositoryRoot.'/proofs/NCK-104/lib.sh');
    $hook = strpos($fixture, 'orb7_set_cleanup_hook restore_home_acl');
    $mutation = strpos($fixture, 'sudo setfacl -m u:caddy:--- /home');

    expect($fixture)
        ->not->toContain('trap restore_home_acl EXIT')
        ->not->toContain('trap - EXIT')->and($hook)
        ->not->toBeFalse()->and($mutation)
        ->not->toBeFalse()->and($hook)->toBeLessThan($mutation)->and($library)->toContain(
            '"$ORB7_ACTIVE_CLEANUP_HOOK"',
        )->toContain('orb7_restore_action "$action" "$@"');
});

it('requires positive pre-signal evidence in the NCK-116 timeout proof', function () {
    $repositoryRoot = dirname(__DIR__, 5);
    $seed = (string) file_get_contents($repositoryRoot.'/proofs/NCK-116/seed-orb-7-timeout.sh');
    $fixture = (string) file_get_contents($repositoryRoot.'/proofs/NCK-116/refuses-a-shifted-rule-number.sh');
    $inspector = (string) file_get_contents($repositoryRoot.'/proofs/NCK-116/inspect-orb-7-timeout.sh');
    $witness = strpos($fixture, 'printf \'installed\\n\' | sudo tee "$ORB7_TIMEOUT_WITNESS"');
    $checkpoint = strpos($fixture, 'orb7_timeout_checkpoint refuses-a-shifted-rule-number');

    expect($seed)
        ->toContain('sudo rm -f -- "$ORB7_TIMEOUT_WITNESS"')
        ->and($fixture)
        ->toContain('sudo test -x "$STUB"')
        ->toContain('sudo test -s "$STUB_STATE"')
        ->toContain('grep -q "# $FOREIGN_RULE\\$" <<<"$numbered"')
        ->toContain('grep -q "# $EXPORTER_RULE_COMMENT\\$" <<<"$numbered"')
        ->toContain('grep -q "# $TRANSIENT_RULE\\$" <<<"$numbered"')
        ->and($witness)
        ->not->toBeFalse()->and($checkpoint)
        ->not->toBeFalse()->and($witness)->toBeLessThan($checkpoint)->and($inspector)->toContain(
            'sudo test -f "$ORB7_TIMEOUT_WITNESS"',
        )->toContain('sudo rm -f -- "$ORB7_TIMEOUT_WITNESS"');
});

it('preserves the historical NCK-73 proof semantics', function () {
    $repositoryRoot = dirname(__DIR__, 5);
    $plan = json_decode(
        (string) file_get_contents($repositoryRoot.'/proofs/NCK-73.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $library = (string) file_get_contents($repositoryRoot.'/proofs/NCK-73/lib.sh');

    expect($plan['setup'])
        ->toBe([[
            'id' => 'metrics-enable-app-dev',
            'node' => 'app-dev',
            'argv' => ['orbit', 'metrics:enable', 'app-dev', '--json'],
            'timeout_seconds' => 600,
        ]])
        ->and($plan['acceptance'][0]['argv'])
        ->toContain('app-prod=desired/active/role_default')
        ->and($library)
        ->not
        ->toContain('$node["wireguard_ip"]')
        ->toContain('$node["wireguard_address"]');
});

it('arms the ORB-7 firewall driver cleanup before its first mutation', function () {
    $repositoryRoot = dirname(__DIR__, 5);
    $fixture = (string) file_get_contents($repositoryRoot.'/proofs/ORB-7/firewall-timeout-restoration.sh');
    $trap = strpos($fixture, 'trap \'driver_cleanup "$?"\' EXIT INT TERM');
    $mutation = strpos($fixture, 'sudo /usr/sbin/ufw allow');

    expect($fixture)
        ->toContain('driver_cleanup()')
        ->and($trap)
        ->not->toBeFalse()->and($mutation)
        ->not->toBeFalse()->and($trap)->toBeLessThan($mutation);
});
