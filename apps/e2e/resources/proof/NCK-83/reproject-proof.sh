#!/usr/bin/env bash
# Standby re-projection proof fixture (NCK-83). Runs as the orbit user on a
# checkout role. Every subcommand exits non-zero when its expectation fails.
set -euo pipefail
cd /
orbit=/home/orbit/orbit/apps/cli/orbit

case ${1-} in
  doctor-projections)
    # A fresh topology carries product projections rendered by the checked-out
    # Gateway code: Doctor reports no projection drift for PHP-FPM pools,
    # workspaces, firewall rules, or DNS on any node. The only tolerated issue
    # is the app-prod Caddy wrapper that NCK-84 owns.
    [[ $# -eq 1 ]] || exit 64
    if json=$("$orbit" doctor --json); then doctor_exit=0; else doctor_exit=$?; fi
    [[ $doctor_exit -eq 0 || $doctor_exit -eq 1 ]] || { printf 'reproject-proof: orbit doctor exited %d\n' "$doctor_exit" >&2; exit 1; }
    DOCTOR_JSON="$json" php <<'PHP'
<?php
declare(strict_types=1);
function fail(string $message): never { fwrite(STDERR, "reproject-proof: {$message}\n"); exit(1); }
$report = json_decode((string) getenv('DOCTOR_JSON'), true, 32, JSON_THROW_ON_ERROR);
$tolerated = [['app-prod', 'instance', 'instance.caddy_projection_mismatch']];
$issues = [];
foreach ($report['nodes'] ?? [] as $node) {
    foreach ($node['families'] as $family) {
        foreach ($family['issues'] as $issue) {
            $issues[] = [$node['node_name'], $family['family'], $issue['code']];
        }
    }
}
if (count($report['nodes'] ?? []) !== 3) fail('report does not cover three nodes');
$unexpected = array_filter($issues, static fn (array $issue): bool => ! in_array($issue, $tolerated, true));
if ($unexpected !== []) fail('unexpected drift: '.json_encode(array_values($unexpected)));
printf("reproject-proof: %d nodes, %d drift issue(s), none outside the NCK-84 Caddy wrapper\n", count($report['nodes']), count($issues));
PHP
    ;;
  pool-markers)
    # The rendered pools carry the current template: app-dev revalidates
    # timestamps on every request (be1fb7c), and every managed pool section
    # carries the directive.
    [[ $# -eq 1 ]] || exit 64
    pool=/etc/php/8.5/fpm/pool.d/orbit-scopes.conf
    sections=$(sudo grep -c '^\[orbit-' "$pool")
    markers=$(sudo grep -c '^php_admin_value\[opcache.validate_timestamps\] = 1$' "$pool")
    [[ "$sections" -ge 2 && "$markers" == "$sections" ]] || { printf 'reproject-proof: %s has %s sections and %s markers\n' "$pool" "$sections" "$markers" >&2; exit 1; }
    printf 'reproject-proof: app-dev pool renders %s sections with the current OPcache template\n' "$sections"
    ;;
  *) exit 64 ;;
esac
