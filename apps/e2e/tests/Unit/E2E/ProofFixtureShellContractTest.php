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
              if [[ "$2" == -f && "$3" == "$ORB7_CLEANUP_ROOT/cleanup-case/rules.tsv" ]]; then
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

it('maps ORB-7 proof to exact candidate fixtures and harness timeout exits', function () {
    $repositoryRoot = dirname(__DIR__, 5);
    $plan = json_decode(
        (string) file_get_contents($repositoryRoot.'/proofs/ORB-7.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    $actions = collect($plan['acceptance'])->keyBy('id');

    expect($plan['fixture_issues'])
        ->toBe(['NCK-73', 'NCK-104', 'NCK-108', 'NCK-116'])
        ->and($plan['mutates'])
        ->toBeTrue()
        ->and($actions->get('pipefail-assertions-remain-truthful')['argv'] ?? null)
        ->toBe(['bash', '/var/lib/orbit-e2e/proof/pipefail-assertions.sh'])
        ->and($actions->get('real-firewall-fixture-times-out')['expected_exit_code'] ?? null)
        ->toBe(124)
        ->and($actions->get('hung-cleanup-is-force-killed')['expected_exit_code'] ?? null)
        ->toBe(137)
        ->and(json_encode($plan, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
        ->toContain('/var/lib/orbit-e2e/proof/NCK-73')
        ->toContain('/var/lib/orbit-e2e/proof/NCK-104')
        ->toContain('/var/lib/orbit-e2e/proof/NCK-108')
        ->toContain('/var/lib/orbit-e2e/proof/NCK-116');
});

it('keeps only the owned ORB-7 proof plan at the top level', function () {
    $repositoryRoot = dirname(__DIR__, 5);

    expect(glob($repositoryRoot.'/proofs/*orb-7*.json'))
        ->toBe([])
        ->and(is_file($repositoryRoot.'/proofs/ORB-7-hung.json'))
        ->toBeFalse();
});

it('arms cleanup before records and exposes both signal windows in every affected fixture', function (
    string $relativePath,
    string $trap,
    string $record,
    string $action,
) {
    $fixture = (string) file_get_contents(dirname(__DIR__, 5).'/proofs/'.$relativePath);
    $trapPosition = strpos($fixture, $trap);
    $recordPosition = strpos($fixture, $record);

    expect($trapPosition)
        ->not->toBeFalse()->and($recordPosition)
        ->not->toBeFalse()->and($trapPosition)->toBeLessThan($recordPosition)->and($fixture)->toContain(
            "orb7_checkpoint $action post-record",
        )->toContain("orb7_checkpoint $action post-mutation");
})->with([
    'NCK-73 recover' => ['NCK-73/recover.sh', 'orb7_service_traps recover', 'orb7_service_record recover', 'recover'],
    'NCK-108 metrics node' => [
        'NCK-108/metrics-node-fails-closed.sh',
        'orb7_service_traps metrics-node-fails-closed',
        'orb7_service_record metrics-node-fails-closed',
        'metrics-node-fails-closed',
    ],
    'NCK-104 prepare roots' => [
        'NCK-104/prepare-roots.sh',
        'orb7_traps prepare-roots',
        'orb7_arm_paths nck104-original-paths',
        'prepare-roots',
    ],
    'NCK-104 retrieve settings' => [
        'NCK-104/retrieve-settings-sql.sh',
        'orb7_traps retrieve-settings-sql',
        'orb7_arm_database nck104-original-database',
        'retrieve-settings-sql',
    ],
    'NCK-104 patch omit null' => [
        'NCK-104/patch-omit-null.sh',
        'orb7_traps patch-omit-null',
        'orb7_arm_database patch-omit-null',
        'patch-omit-null',
    ],
    'NCK-104 CLI parse' => [
        'NCK-104/cli-setting-parse.sh',
        'orb7_traps cli-setting-parse',
        'orb7_arm_database cli-setting-parse',
        'cli-setting-parse',
    ],
    'NCK-104 derived origin' => [
        'NCK-104/derived-explicit-origin.sh',
        'orb7_traps derived-explicit-origin',
        'orb7_arm_database derived-explicit-origin',
        'derived-explicit-origin',
    ],
    'NCK-104 app prod' => [
        'NCK-104/non-migrating-app-prod.sh',
        'orb7_traps non-migrating-app-prod',
        'orb7_arm_database non-migrating-app-prod',
        'non-migrating-app-prod',
    ],
    'NCK-104 root ownership' => [
        'NCK-104/root-ownership.sh',
        'orb7_traps root-ownership',
        'orb7_arm_paths root-ownership',
        'root-ownership',
    ],
    'NCK-104 checkout overlap' => [
        'NCK-104/checkout-overlap.sh',
        'orb7_traps checkout-overlap',
        'orb7_arm_paths checkout-overlap',
        'checkout-overlap',
    ],
    'NCK-104 Caddy ACL' => [
        'NCK-104/caddy-acl-sharing.sh',
        'orb7_traps caddy-acl-sharing',
        'orb7_arm_paths caddy-acl-sharing',
        'caddy-acl-sharing',
    ],
    'NCK-104 recorded removal' => [
        'NCK-104/removal-recorded-origin.sh',
        'orb7_traps removal-recorded-origin',
        'orb7_arm_paths removal-recorded-origin',
        'removal-recorded-origin',
    ],
    'NCK-104 repair origin' => [
        'NCK-104/repair-removal-origin.sh',
        'orb7_traps repair-removal-origin',
        'orb7_arm_database repair-removal-origin',
        'repair-removal-origin',
    ],
    'NCK-104 removal restoration' => [
        'NCK-104/removal-restoration.sh',
        'orb7_traps removal-restoration',
        'orb7_arm_paths removal-restoration',
        'removal-restoration',
    ],
    'NCK-104 restore origin' => [
        'NCK-104/restore-legacy-origin.sh',
        'orb7_traps restore-legacy-origin',
        'orb7_arm_database restore-legacy-origin',
        'restore-legacy-origin',
    ],
    'NCK-116 metrics node' => [
        'NCK-116/escape-metrics-node.sh',
        'orb7_traps escape-metrics-node',
        'orb7_arm escape-metrics-node',
        'escape-metrics-node',
    ],
    'NCK-116 exporter node' => [
        'NCK-116/escape-exporter-node.sh',
        'orb7_traps escape-exporter-node',
        'orb7_arm escape-exporter-node',
        'escape-exporter-node',
    ],
    'NCK-116 no proof' => [
        'NCK-116/refuses-without-proof.sh',
        'orb7_traps refuses-without-proof',
        'orb7_arm refuses-without-proof',
        'refuses-without-proof',
    ],
    'NCK-116 no WireGuard address' => [
        'NCK-116/escape-without-wireguard-address.sh',
        'orb7_traps escape-without-wireguard-address',
        'orb7_arm escape-without-wireguard-address',
        'escape-without-wireguard-address',
    ],
    'NCK-116 shifted rule' => [
        'NCK-116/refuses-a-shifted-rule-number.sh',
        'orb7_traps refuses-a-shifted-rule-number',
        'orb7_arm refuses-a-shifted-rule-number',
        'refuses-a-shifted-rule-number',
    ],
]);

it('keeps the NCK-104 ORB-7 cleanup active while restoring the home ACL', function () {
    $repositoryRoot = dirname(__DIR__, 5);
    $fixture = (string) file_get_contents($repositoryRoot.'/proofs/NCK-104/removal-restoration.sh');
    $library = (string) file_get_contents($repositoryRoot.'/proofs/NCK-104/lib.sh');
    $hook = strpos($fixture, 'orb7_set_cleanup_hook restore_fixture_state');
    $mutation = strpos($fixture, 'sudo setfacl -m u:caddy:--- /home');

    expect($fixture)
        ->not->toContain('trap restore_home_acl EXIT')
        ->not->toContain('orb7_clear_cleanup_hook')->and($hook)
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

it('passes the exact NCK-116 action name through the TERM trap', function () {
    $fixture = dirname(__DIR__, 5).'/proofs/NCK-116/lib.sh';
    $script = <<<'BASH'
        source "$1"

        orb7_term_exit() {
          trap - EXIT INT TERM
          [[ "$1" == sample-action ]] || exit 99
          exit 143
        }

        orb7_traps sample-action
        kill -TERM "$$"
        BASH;

    $result = new Process(['bash', '-c', $script, 'orb7-term-trap', $fixture]);
    $result->run();

    expect($result->getExitCode())->toBe(143, $result->getErrorOutput());
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
            'id' => 'metrics-active-precondition',
            'node' => 'app-dev',
            'argv' => ['orbit', 'metrics:status', '--json'],
            'timeout_seconds' => 120,
        ]])
        ->and($plan['acceptance'][0]['argv'])
        ->toContain('app-prod=desired/active/role_default')
        ->and($library)
        ->not
        ->toContain('$node["wireguard_ip"]')
        ->toContain('$node["wireguard_address"]');
});

it('drives both cleanup windows through the exact staged fixture', function () {
    $repositoryRoot = dirname(__DIR__, 5);
    $driver = (string) file_get_contents($repositoryRoot.'/proofs/ORB-7/actual-fixture-driver.sh');

    expect($driver)
        ->toContain('post-record post-mutation')
        ->toContain('EXIT INT TERM')
        ->toContain('bash "$fixture_root/orb-7-signal-driver.sh"');
});
