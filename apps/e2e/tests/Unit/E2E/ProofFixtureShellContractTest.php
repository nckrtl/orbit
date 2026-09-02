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

it('records an owned firewall identity when no existing rule uses its comment', function () {
    $fixture = dirname(__DIR__, 5).'/proofs/NCK-116/lib.sh';
    $script = <<<'BASH'
        source "$1"

        manifest=$(mktemp)
        trap 'rm -f -- "$manifest"' EXIT

        sudo() {
          case "$1" in
            /usr/sbin/ufw)
              printf 'Status: active\n\n[ 1] BASELINE\n'
              ;;
            tee)
              cat >"$manifest"
              ;;
            *)
              return 1
              ;;
          esac
        }

        orb7_record_ufw_rule escape-metrics-node orbit:test-rule
        [[ "$(cat "$manifest")" == orbit:test-rule ]]
        BASH;

    $result = new Process(['bash', '-c', $script, 'orb7-firewall-delta', $fixture]);
    $result->run();

    expect($result->isSuccessful())->toBeTrue($result->getErrorOutput());
});

it('records consecutive owned firewall identities without globbing the root record', function () {
    $fixture = dirname(__DIR__, 5).'/proofs/NCK-116/lib.sh';
    $script = <<<'BASH'
        source "$1"

        manifest=$(mktemp)
        trap 'rm -f -- "$manifest"' EXIT

        sudo() {
          case "$1" in
            /usr/sbin/ufw)
              printf 'Status: active\n\n[ 1] BASELINE\n'
              ;;
            tee)
              cat >>"$manifest"
              ;;
            *)
              return 1
              ;;
          esac
        }

        orb7_record_ufw_rule escape-without-wireguard-address orbit:first
        orb7_record_ufw_rule escape-without-wireguard-address orbit:second
        [[ "$(cat "$manifest")" == $'orbit:first\norbit:second' ]]
        BASH;

    $result = new Process(['bash', '-c', $script, 'orb7-firewall-deltas', $fixture]);
    $result->run();

    expect($result->isSuccessful())->toBeTrue($result->getErrorOutput());
});

it('restores firewall rules from the root-owned comment manifest', function () {
    $fixture = dirname(__DIR__, 5).'/proofs/NCK-116/lib.sh';
    $script = <<<'BASH'
        source "$1"

        manifest=$(mktemp)
        deleted=$(mktemp)
        trap 'rm -f -- "$manifest" "$deleted"' EXIT
        printf 'orbit:owned\n' >"$manifest"

        sudo() {
          case "$1" in
            /usr/sbin/ufw)
              if [[ "$2" == status ]]; then
                printf 'Status: active\n\n[ 1] BASELINE\n[ 2] ALLOW IN Anywhere # orbit:owned\n'
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

it('publishes an NCK-116 path baseline only after its archive is complete', function () {
    $library = (string) file_get_contents(dirname(__DIR__, 5).'/proofs/NCK-116/lib.sh');
    $capture = strpos($library, 'sudo tar --acls --xattrs --numeric-owner');
    $commit = strpos($library, 'sudo mv -- "$record/paths/$label.tar.pending" "$record/paths/$label.tar"');
    $publish = strpos($library, 'printf \'%s\\t%s\\t1\\n\' "$label" "$path"');

    expect($capture)
        ->not->toBeFalse()->and($commit)
        ->not->toBeFalse()->and($publish)
        ->not->toBeFalse()->and($capture)->toBeLessThan($commit)->and($commit)->toBeLessThan($publish);
});

it('publishes NCK-116 addresses only after the complete snapshot is staged', function () {
    $library = (string) file_get_contents(dirname(__DIR__, 5).'/proofs/NCK-116/lib.sh');

    expect($library)
        ->toContain('sudo tee "$record/addresses.before.pending"')
        ->toContain('sudo mv -- "$record/addresses.before.pending" "$record/addresses.before"');
});

it('records every NCK-116 firewall cleanup identity before creating its rule', function (
    string $relativePath,
    string $record,
    string $mutation,
) {
    $fixture = (string) file_get_contents(dirname(__DIR__, 5).'/proofs/NCK-116/'.$relativePath);
    $recordPosition = strpos($fixture, $record);
    $mutationPosition = strpos($fixture, $mutation);

    expect($recordPosition)
        ->not->toBeFalse()->and($mutationPosition)
        ->not->toBeFalse()->and($recordPosition)->toBeLessThan($mutationPosition);
})->with([
    'exporter decoy' => [
        'escape-exporter-node.sh',
        'orb7_record_ufw_rule escape-exporter-node "$DECOY_RULE"',
        'sudo ufw allow in on orbit proto tcp to "$address" port 9101',
    ],
    'metrics decoy' => [
        'escape-metrics-node.sh',
        'orb7_record_ufw_rule escape-metrics-node "$DECOY_RULE"',
        'sudo ufw allow in on orbit proto tcp to "$address" port 3001',
    ],
    'missing address exporter' => [
        'escape-without-wireguard-address.sh',
        'orb7_record_ufw_rule escape-without-wireguard-address "$EXPORTER_RULE_COMMENT"',
        'sudo ufw allow in on orbit proto tcp from 10.44.0.1 to "$address" port 9100',
    ],
    'missing address decoy' => [
        'escape-without-wireguard-address.sh',
        'orb7_record_ufw_rule escape-without-wireguard-address "$DECOY_RULE"',
        'sudo ufw allow in on orbit proto tcp from 10.44.0.1 to "$address" port 3001',
    ],
    'unowned exporter' => [
        'refuses-without-proof.sh',
        'orb7_record_ufw_rule refuses-without-proof "$EXPORTER_RULE_COMMENT"',
        'sudo ufw allow in on orbit proto tcp to "$address" port 9100',
    ],
    'shifted foreign rule' => [
        'refuses-a-shifted-rule-number.sh',
        'orb7_record_ufw_rule refuses-a-shifted-rule-number "$FOREIGN_RULE"',
        'sudo ufw allow in on orbit proto tcp from 10.44.0.1 to "$address" port 5432',
    ],
    'shifted exporter rule' => [
        'refuses-a-shifted-rule-number.sh',
        'orb7_record_ufw_rule refuses-a-shifted-rule-number "$EXPORTER_RULE_COMMENT"',
        'sudo ufw insert "$foreign_number" allow in on orbit proto tcp from 10.44.0.1',
    ],
    'shifted transient rule' => [
        'refuses-a-shifted-rule-number.sh',
        'orb7_record_ufw_rule refuses-a-shifted-rule-number "$TRANSIENT_RULE"',
        'sudo ufw insert 1 allow in on orbit proto tcp from 10.44.0.1',
    ],
    'timeout foreign rule' => [
        'seed-orb-7-timeout.sh',
        'orb7_record_ufw_rule "$ORB7_TIMEOUT_SEED_ACTION" ORB7-FOREIGN-KEEP',
        'sudo ufw allow in on orbit proto tcp from 10.44.0.1 to "$address" port 5433',
    ],
    'timeout look-alike rule' => [
        'seed-orb-7-timeout.sh',
        'orb7_record_ufw_rule "$ORB7_TIMEOUT_SEED_ACTION" orbit:metrics-node-exporter-v2',
        'sudo ufw allow in on orbit proto tcp from 10.44.0.1 to "$address" port 9101',
    ],
]);

it('records proof-owned Docker resources before creating them', function () {
    $repositoryRoot = dirname(__DIR__, 5);
    $fixture = (string) file_get_contents($repositoryRoot.'/proofs/NCK-116/escape-metrics-node.sh');
    $library = (string) file_get_contents($repositoryRoot.'/proofs/NCK-116/lib.sh');
    $containerRecord = strpos(
        $fixture,
        'orb7_record_docker_resource escape-metrics-node container "$DECOY_CONTAINER"',
    );
    $containerCreate = strpos($fixture, 'docker container create --name "$DECOY_CONTAINER"');
    $volumeRecord = strpos(
        $fixture,
        'orb7_record_docker_resource escape-metrics-node volume "$DECOY_VOLUME"',
    );
    $volumeCreate = strpos($fixture, 'docker volume create');

    expect($containerRecord)
        ->not->toBeFalse()->and($containerCreate)
        ->not->toBeFalse()->and($volumeRecord)
        ->not->toBeFalse()->and($volumeCreate)
        ->not->toBeFalse()->and($containerRecord)->toBeLessThan($containerCreate)->and($volumeRecord)->toBeLessThan(
            $volumeCreate,
        )->and($fixture)->toContain('--label "com.orbit.e2e.cleanup=escape-metrics-node"')->and($library)->toContain(
            'com.orbit.e2e.cleanup',
        )->toContain('[[ "$owner" == "$action" ]]');
});

it('checks recorded NCK-116 firewall comments before and after signal cleanup', function () {
    $driver = (string) file_get_contents(
        dirname(__DIR__, 5).'/proofs/NCK-116/orb-7-signal-driver.sh',
    );

    expect($driver)
        ->toContain('while IFS= read -r comment; do')
        ->toContain('firewall_rule_exists "$comment"')
        ->not->toContain('grep -Fxq "$shape"');
});

it('maps ORB-7 proof to authorized cleanup and timeout boundaries', function () {
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
        ->and($actions->get('representative-shared-cleanup-primitives')['argv'] ?? null)
        ->toBe(['bash', '/var/lib/orbit-e2e/proof/representative-cleanup-matrix.sh'])
        ->and($actions->get('nck-73-actual-cleanup')['argv'] ?? null)
        ->toContain('/var/lib/orbit-e2e/proof/NCK-73')
        ->and($actions->get('nck-108-actual-cleanup')['argv'] ?? null)
        ->toContain('/var/lib/orbit-e2e/proof/NCK-108')
        ->and($actions->get('nck-104-local-path-cleanup')['argv'] ?? null)
        ->toContain('/var/lib/orbit-e2e/proof/nck-104-cleanup-matrix.sh')
        ->and($actions->get('firewall-timeout-cleanup')['argv'] ?? null)
        ->toBe(['bash', '/var/lib/orbit-e2e/proof/firewall-timeout-cleanup-matrix.sh'])
        ->and($actions->get('hung-cleanup-is-force-killed')['argv'] ?? null)
        ->toBe(['bash', '/var/lib/orbit-e2e/proof/hung-cleanup-matrix.sh'])
        ->and(collect($plan['acceptance'])
            ->contains(
                fn (array $action): bool => array_key_exists(
                    'expected_exit_code',
                    $action,
                ),
            ))
        ->toBeFalse()
        ->and(json_encode($plan, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
        ->toContain('/var/lib/orbit-e2e/proof/actual-fixture-cleanup-matrix.sh')
        ->not->toContain('nck-116-cleaned-node-precondition')
        ->not->toContain('nck-116-app-prod-cleanup')
        ->not->toContain('nck-116-metrics-cleanup');

    $nck116ActionIds = collect($plan['acceptance'])
        ->filter(fn (array $action): bool => str_contains(
            json_encode($action['argv'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            '/NCK-116',
        ))
        ->pluck('id')
        ->all();

    expect($nck116ActionIds)->toBe([]);
});

it('inspects intentional timeout exits inside zero-exit proof actions', function (): void {
    $repositoryRoot = dirname(__DIR__, 5);
    $firewall = (string) file_get_contents($repositoryRoot.'/proofs/ORB-7/firewall-timeout-cleanup-matrix.sh');
    $hung = (string) file_get_contents($repositoryRoot.'/proofs/ORB-7/hung-cleanup-matrix.sh');

    expect($firewall)
        ->toContain('timeout --signal=TERM --kill-after=5s 30s')
        ->toContain('[[ "$status" -eq 124 ]]')
        ->toContain('inspect-orb-7-timeout.sh')
        ->and($hung)
        ->toContain('timeout --signal=TERM --kill-after=5s 2s')
        ->toContain('[[ "$status" -eq 137 ]]')
        ->toContain('inspect-hung-cleanup.sh');
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
    $library = (string) file_get_contents($repositoryRoot.'/proofs/NCK-116/lib.sh');
    $seedTrap = strpos($seed, 'trap \'seed_cleanup "$?"\' EXIT');
    $seedRecord = strpos($seed, 'orb7_arm "$ORB7_TIMEOUT_SEED_ACTION"');
    $baselineMutation = strpos($seed, 'sudo /usr/sbin/ufw --force delete "$exporter_number"');
    $baselineRestored = strpos($library, '[[ "$(orb7_ufw_shapes)" == "$before" ]]');
    $baselineRecordReleased = strpos($library, 'sudo rm -rf -- "$ORB7_TIMEOUT_BASELINE_RECORD"');
    $witness = strpos($fixture, 'printf \'installed\\n\' | sudo tee "$ORB7_TIMEOUT_WITNESS"');
    $checkpoint = strpos($fixture, 'orb7_timeout_checkpoint refuses-a-shifted-rule-number');

    expect($seed)
        ->toContain('sudo rm -f -- "$ORB7_TIMEOUT_WITNESS"')
        ->and($seedTrap)
        ->not->toBeFalse()->and($seedRecord)
        ->not->toBeFalse()->and($baselineMutation)
        ->not->toBeFalse()->and($seedTrap)->toBeLessThan($seedRecord)->and($seedRecord)->toBeLessThan(
            $baselineMutation,
        )->and($fixture)->toContain('sudo test -x "$STUB"')->toContain('sudo test -s "$STUB_STATE"')->toContain(
            'grep -q "# $FOREIGN_RULE\\$" <<<"$numbered"',
        )->toContain('grep -q "# $EXPORTER_RULE_COMMENT\\$" <<<"$numbered"')->toContain(
            'grep -q "# $TRANSIENT_RULE\\$" <<<"$numbered"',
        )->and($witness)
        ->not->toBeFalse()->and($checkpoint)
        ->not->toBeFalse()->and($witness)->toBeLessThan($checkpoint)->and($inspector)->toContain(
            'sudo test -f "$ORB7_TIMEOUT_WITNESS"',
        )->toContain('orb7_restore_timeout_seed')->toContain('sudo rm -f -- "$ORB7_TIMEOUT_WITNESS"')->and(
            $library,
        )->toContain('orb7_restore_timeout_seed()')->toContain('ORB7_TIMEOUT_BASELINE_RECORD')->toContain(
            'sudo /usr/sbin/ufw allow in on orbit proto tcp',
        )
        ->not->toContain('sudo /usr/sbin/ufw insert "$exporter_number"')->and($baselineRestored)
        ->not->toBeFalse()->and($baselineRecordReleased)
        ->not->toBeFalse()->and($baselineRestored)->toBeLessThan($baselineRecordReleased);
});

it('publishes the timeout baseline before deleting its exporter rule', function () {
    $seed = (string) file_get_contents(
        dirname(__DIR__, 5).'/proofs/NCK-116/seed-orb-7-timeout.sh',
    );
    $publish = strpos(
        $seed,
        'sudo mv -- "$baseline_pending" "$ORB7_TIMEOUT_BASELINE_RECORD"',
    );
    $mutation = strpos($seed, 'sudo /usr/sbin/ufw --force delete "$exporter_number"');

    expect($seed)
        ->toContain('baseline_pending="$ORB7_TIMEOUT_BASELINE_RECORD.pending"')
        ->toContain('sudo rm -rf -- "$baseline_pending"')
        ->and($publish)
        ->not->toBeFalse()->and($mutation)
        ->not->toBeFalse()->and($publish)->toBeLessThan($mutation);
});

it('keeps timeout seed state only after a zero exit', function () {
    $seed = (string) file_get_contents(
        dirname(__DIR__, 5).'/proofs/NCK-116/seed-orb-7-timeout.sh',
    );

    expect($seed)
        ->toContain('if [[ "$status" -ne 0 ]]; then')
        ->not->toContain('committed=');
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

it('preserves the current NCK-73 proof semantics', function () {
    $repositoryRoot = dirname(__DIR__, 5);
    $plan = json_decode(
        (string) file_get_contents($repositoryRoot.'/proofs/NCK-73.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $library = (string) file_get_contents($repositoryRoot.'/proofs/NCK-73/lib.sh');

    expect($plan['setup'])
        ->toBe([[
            'id' => 'metrics-baseline-active',
            'node' => 'app-dev',
            'argv' => [
                'bash',
                '/var/lib/orbit-e2e/proof/status-active.sh',
                'app-dev=desired/active/metrics_node',
                'app-prod=desired/active/role_default',
                'gateway=desired/active/role_default',
            ],
            'timeout_seconds' => 120,
        ]])
        ->and($plan['mutates'])
        ->toBeTrue()
        ->and($plan['acceptance'][0]['argv'])
        ->toContain('app-prod=desired/active/role_default')
        ->and($library)
        ->toContain('$node["wireguard_ip"]')
        ->not->toContain('$node["wireguard_address"]');
});

it('labels representative evidence and invokes the actual NCK-104 helper boundary', function () {
    $repositoryRoot = dirname(__DIR__, 5);
    $matrix = (string) file_get_contents($repositoryRoot.'/proofs/ORB-7/representative-cleanup-matrix.sh');
    $fixture = (string) file_get_contents($repositoryRoot.'/proofs/ORB-7/representative-cleanup-fixture.sh');
    $plan = (string) file_get_contents($repositoryRoot.'/proofs/ORB-7.json');
    $patch = (string) file_get_contents($repositoryRoot.'/proofs/NCK-104/patch-omit-null.sh');
    $cli = (string) file_get_contents($repositoryRoot.'/proofs/NCK-104/cli-setting-parse.sh');
    $actualMatrix = (string) file_get_contents($repositoryRoot.'/proofs/ORB-7/actual-fixture-cleanup-matrix.sh');
    $nck104Matrix = (string) file_get_contents($repositoryRoot.'/proofs/ORB-7/nck-104-cleanup-matrix.sh');

    expect($matrix)
        ->toContain('representative shared cleanup primitive')
        ->toContain('post-record post-mutation')
        ->toContain('EXIT INT TERM')
        ->toContain('sudo touch -- "$checkpoint.continue"')
        ->toContain('sudo test -x "$stub"')
        ->toContain('[[ "$(sudo cat "$target")" == mutated ]]')
        ->and($fixture)
        ->toContain("trap 'cleanup \"\$?\"' EXIT")
        ->toContain('checkpoint post-record')
        ->toContain('checkpoint post-mutation')
        ->toContain('until sudo test -f "$checkpoint.continue"; do')
        ->and($patch)
        ->toContain('--setting=instance.path:/mnt/orbit-apps')
        ->toContain('settings.worktree.path')
        ->and($cli)
        ->toContain('--setting=instance.path:/srv/orbit:data/instances')
        ->toContain('--setting=worktree.path:')
        ->and($plan)
        ->not->toContain('advance')->toContain('nck-104-cleanup-matrix.sh')->and($actualMatrix)->toContain(
            '[[ -f "$proof_root/orb-7-signal-driver.sh" ]]',
        )
        ->not->toContain('[[ -x "$proof_root/orb-7-signal-driver.sh" ]]')->toContain(
            'orb-7-signal-driver.sh',
        )->toContain('post-record post-mutation')->toContain('EXIT INT TERM')->and($nck104Matrix)->toContain(
            'readonly proof_root=/var/lib/orbit-e2e/proof/NCK-104',
        )->toContain('source "$proof_root/lib.sh"')->toContain('prepare-roots')->toContain(
            'removal-restoration',
        )->toContain('orb7_restore_action');
});

it('makes NCK-104 path records recoverable during arming', function () {
    $helper = (string) file_get_contents(dirname(__DIR__, 5).'/proofs/NCK-104/orb-7-node-state.sh');
    $state = strpos($helper, "printf 'arming\\n'");
    $record = strpos($helper, 'printf \'%s\\t%s\\t%s\\n\' "$index" "$path" "$existed"');
    $capture = strpos($helper, 'sudo tar --acls --xattrs --numeric-owner');

    expect($helper)
        ->toContain('recover_record "$action"')
        ->toContain('restore "$action"')
        ->toContain('sudo test ! -e "$record"')
        ->and($state)
        ->not->toBeFalse()->and($record)
        ->not->toBeFalse()->and($capture)
        ->not->toBeFalse()->and($state)->toBeLessThan($capture)->and($capture)->toBeLessThan($record);
});

it('holds EXIT cleanup until each driver records its pre-exit evidence', function () {
    $repositoryRoot = dirname(__DIR__, 5);

    foreach (['NCK-73', 'NCK-108', 'NCK-116'] as $issue) {
        $library = (string) file_get_contents("$repositoryRoot/proofs/$issue/lib.sh");
        $driver = (string) file_get_contents("$repositoryRoot/proofs/$issue/orb-7-signal-driver.sh");

        expect($library)
            ->toContain('until sudo test -f "${ORBIT_E2E_ORB7_CHECKPOINT:?}.continue"; do sleep 0.1; done')
            ->and($driver)
            ->toContain('sudo touch -- "$checkpoint.continue"')
            ->toContain('sudo rm -f -- "$checkpoint" "$checkpoint.continue"');
    }

    $library = (string) file_get_contents($repositoryRoot.'/proofs/NCK-104/lib.sh');
    $matrix = (string) file_get_contents($repositoryRoot.'/proofs/ORB-7/nck-104-cleanup-matrix.sh');

    expect($library)
        ->toContain('until sudo test -f "${ORBIT_E2E_ORB7_CHECKPOINT:?}.continue"; do sleep 0.1; done')
        ->and($matrix)
        ->toContain('sudo touch -- "$checkpoint.continue"')
        ->toContain('sudo rm -f -- "$checkpoint" "$checkpoint.continue"')
        ->toContain('did not create its database mutation');
});

it('marks NCK-104 remote-only records on their owning nodes', function () {
    $matrix = (string) file_get_contents(dirname(__DIR__, 5).'/proofs/ORB-7/nck-104-cleanup-matrix.sh');

    expect($matrix)
        ->toContain('orb7_remote_state app-dev mark "$action" active')
        ->toContain('orb7_remote_state gateway mark "$action" active');
});

it('runs each NCK-104 cleanup family as its own proof action', function () {
    $repositoryRoot = dirname(__DIR__, 5);
    $plan = json_decode(
        (string) file_get_contents($repositoryRoot.'/proofs/ORB-7.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $actions = collect($plan['acceptance'])
        ->filter(fn (array $action): bool => str_starts_with($action['id'], 'nck-104-'))
        ->values();

    expect($actions->pluck('id')->all())
        ->toBe([
            'nck-104-local-path-cleanup',
            'nck-104-local-database-cleanup',
            'nck-104-remote-path-cleanup',
            'nck-104-remote-database-cleanup',
            'nck-104-custom-hook-cleanup',
        ])
        ->and($actions->map(fn (array $action): string => $action['argv'][2])->all())
        ->toBe([
            'local-path',
            'local-database',
            'remote-path',
            'remote-database',
            'custom-hook',
        ]);
});

it('uses fixture-owned database evidence and reaps an active NCK-104 matrix child', function () {
    $matrix = (string) file_get_contents(dirname(__DIR__, 5).'/proofs/ORB-7/nck-104-cleanup-matrix.sh');

    expect($matrix)
        ->not
        ->toContain('database_before=$(sha256sum')
        ->toContain('! database_has_mutation || fail "$family changed its database before mutation"')
        ->toContain('! database_has_mutation || fail "$family left its database mutation"')
        ->toContain('trap matrix_cleanup EXIT')
        ->toContain('kill -TERM -- "-$active_pid"')
        ->toContain('active_pid=');
});

it('clears the NCK-104 restore rate limit before restarting an inherited active service', function () {
    $helper = (string) file_get_contents(dirname(__DIR__, 5).'/proofs/NCK-104/orb-7-node-state.sh');
    $reset = strpos($helper, 'sudo systemctl reset-failed php8.5-fpm');
    $start = strpos($helper, 'sudo systemctl start php8.5-fpm');

    expect($reset)
        ->not->toBeFalse()->and($start)
        ->not->toBeFalse()->and($reset)->toBeLessThan($start);
});
